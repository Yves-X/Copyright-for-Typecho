#!/usr/bin/env bash
#
# 端到端测试：安装一个真实的 Typecho 站点，从 1.0.4 升级到当前版本并检查前台输出。
#
# 用法：tests/e2e.sh [Typecho 版本或分支，默认 1.3.0]
# 环境变量：PORT（默认 8090）、WORK（工作目录，默认临时目录）

set -euo pipefail

VERSION="${1:-1.3.0}"
# 版本号对应 v 开头的标签，也可以是分支名，如 master
REF="$([[ "$VERSION" =~ ^[0-9] ]] && echo "v$VERSION" || echo "$VERSION")"
PORT="${PORT:-8090}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# Typecho 1.2.0 安装时要求 SQLite 文件路径的目录名里没有点，mktemp 默认的 tmp.XXXX 会被拒绝
# macOS 的 TMPDIR 以 / 结尾，拼出的 // 同样会被拒绝
TMP="${TMPDIR:-/tmp}"
WORK="${WORK:-$(mktemp -d "${TMP%/}/copyright-XXXXXXXX")}"
SITE="$WORK/site"
DB="$SITE/usr/copyright-test.db"
BASE="http://127.0.0.1:$PORT"
JAR="$WORK/cookies"
ERRORS="$WORK/php-errors.log"
PINGS="$WORK/pings.log"
PLUGIN="$SITE/usr/plugins/Copyright"

failures=0
pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; failures=$((failures + 1)); }

# expect <描述> <页面> <应包含的固定字符串>
expect() { if grep -qF -- "$3" <<<"$2"; then pass "$1"; else fail "$1"; fi; }
# reject <描述> <页面> <不应包含的固定字符串>
reject() { if grep -qF -- "$3" <<<"$2"; then fail "$1"; else pass "$1"; fi; }

db() { php "$ROOT/tests/db.php" "$DB" "$@"; }
get() { curl -sS "$BASE$1"; }
admin_get() { curl -fsS -c "$JAR" -b "$JAR" "$BASE$1"; }
# Typecho 的 token 与来源页绑定，所以提交时带上取得 token 的页面作为 Referer
# admin_post <来源页> <地址> [curl 参数…]
admin_post() { curl -fsS -o /dev/null -c "$JAR" -b "$JAR" -e "$BASE$1" "${@:2}"; }
# admin_fetch <来源页> <地址> [curl 参数…]：与 admin_post 相同，但输出响应
admin_fetch() { curl -fsS -c "$JAR" -b "$JAR" -e "$BASE$1" "${@:2}"; }
# preview <来源页> <地址> [curl 参数…]：请求预览，输出其中的 HTML
preview() { admin_fetch "$@" | php -r 'echo json_decode(stream_get_contents(STDIN))->html;'; }
# preview_url <页面内容>：取出预览的请求地址
preview_url() { grep -o 'data-url="[^"]*do=preview[^"]*"' <<<"$1" | head -1 | cut -d'"' -f2 | sed 's/&amp;/\&/g'; }
# form_action <页面内容> <地址中的关键字>：取出表单的提交地址
form_action() { grep -o "action=\"[^\"]*$2[^\"]*\"" <<<"$1" | head -1 | cut -d'"' -f2 | sed 's/&amp;/\&/g'; }
# 后台提示保存在 Cookie 中，由页面脚本显示
notice() { php "$ROOT/tests/notice.php" "$JAR"; }

plugin_action() {
    local token
    token="$(admin_get /admin/plugins.php | grep -o '_=[0-9a-f]\{32\}' | head -1)"
    admin_post /admin/plugins.php "$BASE/index.php/action/plugins-edit?$1=Copyright&$token"
}

install_files() {
    rm -rf "$PLUGIN" && mkdir -p "$PLUGIN"
    cp "$ROOT"/*.php "$PLUGIN/"
    cp -R "$ROOT/assets" "$PLUGIN/"
}

cleanup() { [[ -n "${SERVER_PID:-}" ]] && kill "$SERVER_PID" 2>/dev/null || true; }
trap cleanup EXIT

echo "Typecho $VERSION · $(php -r 'echo PHP_VERSION;') · $WORK"

# 1. 安装站点
# 先取引用再剥到提交：Typecho 有的标签指向另一个标签，git clone --branch 会因此报 is not a commit
if [[ ! -d "$WORK/typecho-$VERSION" ]]; then
    git init -q "$WORK/typecho-$VERSION"
    git -C "$WORK/typecho-$VERSION" fetch -q --depth 1 https://github.com/typecho/typecho.git "$REF"
    git -C "$WORK/typecho-$VERSION" checkout -q --detach 'FETCH_HEAD^{commit}'
fi
rm -rf "$SITE" "$JAR" && mkdir -p "$SITE"
(cd "$WORK/typecho-$VERSION" && git archive HEAD) | tar -x -C "$SITE"
# 安装程序把错误也输出到标准输出，失败时原样打印出来
if ! install_log="$(cd "$SITE" && TYPECHO_SITE_URL="$BASE" TYPECHO_DB_ADAPTER=Pdo_SQLite TYPECHO_DB_FILE="$DB" \
    TYPECHO_USER_PASSWORD=copyright-test php install.php 2>&1)"; then
    echo "安装 Typecho 失败：" >&2
    echo "$install_log" >&2
    exit 1
fi
# 让 Typecho 把异常直接输出到页面，便于定位失败
sed -i.bak "1a define('__TYPECHO_DEBUG__', true);" "$SITE/config.inc.php"
# 安装时文章的发布时间就是当前这一秒，Typecho 只显示早于当前时间的内容
db sql 'UPDATE typecho_contents SET created = created - 3600' >/dev/null

: >"$ERRORS"
: >"$PINGS"
COPYRIGHT_PING_LOG="$PINGS" php -d error_reporting=E_ALL -d display_errors=0 -d log_errors=1 -d error_log="$ERRORS" \
    -S "127.0.0.1:$PORT" -t "$SITE" "$ROOT/tests/router.php" >/dev/null 2>&1 &
SERVER_PID=$!
for _ in $(seq 50); do curl -fs -o /dev/null "$BASE/" && break; sleep 0.1; done

admin_post /admin/login.php "$(form_action "$(admin_get /admin/login.php)" login)" \
    --data 'name=typecho&password=copyright-test&referer=' 
admin_get /admin/welcome.php >/dev/null

# 2. 以 1.0.4 启用并保存设置，再只覆盖文件升级
echo "升级"
rm -rf "$PLUGIN" && mkdir -p "$PLUGIN" && cp "$ROOT/tests/fixtures/Plugin-1.0.4.php" "$PLUGIN/Plugin.php"
plugin_action activate
db config plugin:Copyright '{"author":"Yves X","notice":"转载时须注明出处及本声明","showURL":["1"],"showOnPost":["1"],"showOnPage":null}'
install_files

post="$(get /index.php/archives/1/)"
expect "保留 1.x 设置的作者" "$post" '版权属于：Yves X</p>'
expect "保留 1.x 设置的本文链接" "$post" "本文链接：<a class=\"content-copyright\" href=\"$BASE/index.php/archives/1/\">"
expect "保留 1.x 设置的声明" "$post" '转载时须注明出处及本声明</p>'
expect "1.x 的样式类名不变" "$post" '<hr class="content-copyright" /><blockquote class="content-copyright">'
expect "首次访问即补注册 2.0 钩子" "$(db option plugins)" 'stripStatement'
reject "不再保留 1.x 回调" "$(db option plugins)" 'Copyright_Plugin'
expect "补注册迁移用的 action" "$(db option actionTable)" 'copyright'
expect "补注册后设置仍在" "$(db option plugin:Copyright)" 'Yves X'

post="$(get /index.php/archives/1/)"
expect "补注册后输出正常" "$post" '版权属于：Yves X</p>'
reject "不输出任何样式" "$post" 'class="content-copyright" style='
reject "页头不输出样式" "$post" 'copyright-plugin'
reject "摘要和描述中不含版权信息" "$(grep -o '<meta name="description"[^>]*>' <<<"$post")" '版权属于'

# 3. 显示规则
echo "显示规则"
db field 1 copyrightDisplay 1
reject "首页列表不输出" "$(get /)" '<blockquote class="content-copyright"'
reject "RSS 默认不输出" "$(get /index.php/feed/)" '版权属于'
reject "独立页面默认不输出" "$(get /index.php/start-page.html)" 'content-copyright"'
db field 2 copyrightDisplay 1
expect "字段 copyrightDisplay=1 可单独开启独立页面" "$(get /index.php/start-page.html)" '版权属于：Yves X'
db field 2 copyrightDisplay
db field 1 copyrightDisplay 0
reject "字段 copyrightDisplay=0 可单独关闭" "$(get /index.php/archives/1/)" 'content-copyright"'
db field 1 copyrightDisplay

db config plugin:Copyright '{"author":"Yves X","notice":"n","showPermalink":["1"],"display":["post","feed"]}'
expect "开启后输出到 RSS" "$(get /index.php/feed/)" '版权属于：Yves X'

# 4. 字段与转义
echo "字段与转义"
db config plugin:Copyright '{"author":"Yves X","notice":"转载请注明 <a href=\"https://example.com\" onclick=\"x()\">出处</a><script>alert(1)</script>","showPermalink":["1"],"display":["post"],"license":"by-nc-sa"}'
post="$(get /index.php/archives/1/)"
expect "输出 CC 协议链接" "$post" 'rel="license noopener" target="_blank" href="https://creativecommons.org/licenses/by-nc-sa/4.0/">CC BY-NC-SA 4.0</a>'
expect "页头输出 rel=license" "$post" '<link rel="license" href="https://creativecommons.org/licenses/by-nc-sa/4.0/" />'
expect "协议名悬停显示白话说明" "$post" 'title="须署名，不得商用，改编后须以相同协议分享" rel="license noopener"'
expect "声明保留安全的链接" "$post" '转载请注明 <a href="https://example.com">出处</a>alert(1)'
reject "声明去掉事件属性" "$post" 'onclick="x()"'
reject "声明去掉 script" "$post" '<script>alert(1)'

db field 1 copyrightAuthor '<img src=x onerror=alert(1)>'
db field 1 copyrightNotice 0
post="$(get /index.php/archives/1/)"
expect "作者被转义" "$post" '版权属于：&lt;img src=x onerror=alert(1)&gt;'
reject "notice=0 隐藏声明" "$post" '转载请注明'

db field 1 copyrightSourceUrl 'javascript://%0aalert(1)'
post="$(get /index.php/archives/1/)"
reject "拒绝 javascript: 链接" "$post" 'javascript:'
db field 1 copyrightSourceUrl 'https://example.org/original'
db field 1 copyrightAuthor
db field 1 copyrightNotice
post="$(get /index.php/archives/1/)"
expect "转载显示原文链接" "$post" '原文链接：<a class="content-copyright" href="https://example.org/original" target="_blank" rel="noopener">'
reject "转载不套用全局作者" "$post" 'Yves X'
reject "转载不套用全局协议" "$post" 'CC BY-NC-SA'
reject "转载不套用全局声明" "$post" '转载请注明'
db field 1 copyrightLicense by
expect "转载可单独设置协议" "$(get /index.php/archives/1/)" '>CC BY 4.0</a>'
db field 1 copyrightSourceUrl
db field 1 copyrightLicense all-rights-reserved
expect "保留所有权利单独成行" "$(get /index.php/archives/1/)" '<p class="content-copyright">保留所有权利</p>'
db field 1 copyrightLicense
db field 1 copyrightNotice

# 5. 模板与输出方式
echo "模板与输出方式"
db config plugin:Copyright '{"author":"Yves X","showPermalink":["1"],"display":["post"],"license":"cc0","template":"<div class=\"my\">{author}|{title}|{licenseName}|{site}</div>"}'
expect "自定义内容模版" "$(get /index.php/archives/1/)" '<!--copyright--><div class="my">Yves X|欢迎使用 Typecho|CC0 1.0|'
db config plugin:Copyright '{"author":"Yves X","display":["post"],"style":".content-copyright { color: red; }</style><script>"}'
post="$(get /index.php/archives/1/)"
expect "自定义样式输出到 head" "$post" '<style>.content-copyright { color: red; }/style>script></style>'
reject "自定义样式不能提前结束 style" "$post" '<script>"'
db config plugin:Copyright '{"author":"Yves X","display":["post"],"placement":"manual"}'
reject "由主题调用时不自动追加" "$(get /index.php/archives/1/)" '版权属于'

db config plugin:Copyright '{"author":"Yves X","display":[]}'
reject "显示位置全不勾选时不显示" "$(get /index.php/archives/1/)" '版权属于'
config="$(admin_get '/admin/options-plugin.php?config=Copyright')"
expect "设置页有显示位置" "$config" 'name="display[]"'
expect "设置页有协议下拉框和辅助选择" "$config" '<details class="copyright-chooser"'
expect "辅助选择脚本可访问" "$(get /usr/plugins/Copyright/assets/license-chooser.js)" 'copyright-chooser'
expect "设置页样式可访问" "$(get /usr/plugins/Copyright/assets/admin.css)" '.copyright-chooser'
reject "没有旧字段时不显示迁移" "$config" 'copyright-migration'

# 6. 编辑页
echo "编辑页"
db config plugin:Copyright '{"author":"Yves X","notice":"转载时须注明出处及本声明","showPermalink":["1"],"display":["post"],"license":"by-nd"}'
editor="$(admin_get '/admin/write-post.php?cid=1')"
expect "编辑页标出全局开关" "$editor" '套用全局设置（显示）'
expect "编辑页标出全局作者" "$editor" 'value="inherit" /> 套用全局设置（Yves X）</label>'
expect "编辑页标出全局协议" "$editor" '套用全局设置（CC BY-ND 4.0）'
expect "编辑页标出全局声明" "$editor" '套用全局设置（转载时须注明出处及本声明）'
expect "编辑页可选自定义或不显示" "$editor" 'name="copyright-mode-copyrightAuthor" value="off" /> 不显示</label>'
expect "编辑页可选原创或转载" "$editor" 'name="copyright-origin" value="repost"'
expect "独立页面编辑页按独立页面的设置标出" "$(admin_get '/admin/write-page.php?cid=2')" '套用全局设置（不显示）'

url="$(preview_url "$editor")"
expect "编辑页有预览" "$editor" 'class="copyright-preview"'
html="$(preview '/admin/write-post.php?cid=1' "$url" --data 'context=editor&type=post&cid=1' --data-urlencode 'title=改过的标题' --data-urlencode 'fields[copyrightAuthor]=预览作者')"
expect "预览使用编辑中的作者" "$html" '版权属于：预览作者'
expect "预览使用编辑中的标题" "$(preview '/admin/write-post.php?cid=1' "$url" --data 'context=editor&type=post&cid=1' --data-urlencode 'title=改过的标题' --data-urlencode 'fields[copyrightDisplay]=1')" 'CC BY-ND 4.0'
html="$(preview '/admin/write-post.php?cid=1' "$url" --data 'context=editor&type=post&cid=1' --data-urlencode 'fields[copyrightSourceUrl]=https://example.org/a')"
expect "预览转载显示原文链接" "$html" '原文链接'
reject "预览转载不套用全局作者" "$html" 'Yves X'
expect "预览不显示时说明" "$(preview '/admin/write-post.php?cid=1' "$url" --data 'context=editor&type=post&cid=1&fields[copyrightDisplay]=0')" '不显示版权信息'
expect "新文章也能预览" "$(preview '/admin/write-post.php' "$(preview_url "$(admin_get /admin/write-post.php)")" --data 'context=editor&type=post&cid=0')" '版权属于：Yves X'

config="$(admin_get '/admin/options-plugin.php?config=Copyright')"
html="$(preview '/admin/options-plugin.php?config=Copyright' "$(preview_url "$config")" --data 'context=settings&display[]=post&author=新作者&license=cc0&notice=&placement=auto&template=&style=')"
expect "设置页预览未保存的设置" "$html" '版权属于：新作者'
expect "设置页预览未保存的协议" "$html" 'CC0 1.0'

expect "文章编辑页有许可协议字段" "$editor" 'name="fields[copyrightLicense]"'
expect "文章编辑页有开关字段" "$editor" 'name="fields[copyrightDisplay]"'
expect "独立页面编辑页有许可协议字段" "$(admin_get '/admin/write-page.php?cid=2')" 'name="fields[copyrightLicense]"'
[[ "$(grep -o 'name="fields\[copyrightLicense\]"' <<<"$editor" | wc -l)" -eq 1 ]] && pass "字段只注册一次" || fail "字段只注册一次"

# 7. 旧字段与迁移
echo "旧字段与迁移"
config_page=/admin/options-plugin.php?config=Copyright
db field 1 switch 0
reject "升级后仍读取旧字段 switch" "$(get /index.php/archives/1/)" '<blockquote class="content-copyright"'
db field 1 copyrightDisplay 1
expect "新字段优先于旧字段" "$(get /index.php/archives/1/)" '<blockquote class="content-copyright"'
db field 1 copyrightDisplay
db config plugin:Copyright '{"author":"Yves X","notice":"n","display":["post"],"legacyFields":null}'
expect "关闭兼容后不读取旧字段" "$(get /index.php/archives/1/)" '<blockquote class="content-copyright"'

db field 1 notice 'old notice'
db field 1 copyrightNotice 'new notice'
db field 2 notice 'page notice'
db field 2 copyrightNotice ''
db sql "INSERT INTO typecho_fields (cid, name, type, str_value, int_value, float_value) VALUES (2, 'switch', 'int', NULL, 1, 0)" >/dev/null
db field 1 url 'https://theme.example/'
config="$(admin_get "$config_page")"
expect "设置页预览旧字段" "$config" 'name="legacy[]" value="switch"'
expect "预览列出用量" "$config" '<summary>2 处</summary>'
expect "预览完整列出内容和值并链接" "$config" 'write-page.php?cid=2" target="_blank">关于</a>：1</li>'
expect "关闭兼容时预览说明不会读取" "$config" '目前不会读取'

admin_post "$config_page" "$(form_action "$config" action/copyright)" \
    --data-urlencode 'legacy[]=switch' --data-urlencode 'legacy[]=notice'
fields="$(db sql 'SELECT cid, name, type, str_value, int_value FROM typecho_fields ORDER BY cid, name')"
expect "迁移 int 类型的字段" "$fields" '{"cid":2,"name":"copyrightDisplay","type":"int","str_value":null,"int_value":1}'
expect "新字段为空时用旧值覆盖" "$fields" '{"cid":2,"name":"copyrightNotice","type":"str","str_value":"page notice","int_value":0}'
expect "新字段已有不同的值时保留新字段" "$fields" '{"cid":1,"name":"copyrightNotice","type":"str","str_value":"new notice","int_value":0}'
expect "冲突的旧字段原样保留" "$fields" '{"cid":1,"name":"notice","type":"str","str_value":"old notice","int_value":0}'
reject "迁移后不再有旧字段 switch" "$fields" '"name":"switch"'
expect "未勾选的旧字段不动" "$fields" '{"cid":1,"name":"url","type":"str","str_value":"https:\/\/theme.example\/","int_value":0}'
expect "迁移后提示结果" "$(notice)" '已迁移 3 个字段，另有 1 个因新字段已有不同的值而保留原样'
expect "迁移后的字段生效" "$(get /index.php/start-page.html)" 'page notice'
db sql 'DELETE FROM typecho_fields' >/dev/null

db field 2 switch 1
admin_post "$config_page" "$BASE/index.php/action/copyright?do=migrate-fields&_=0" --data 'legacy[]=switch' || true
expect "token 不对时不迁移" "$(db sql 'SELECT name FROM typecho_fields')" '"name":"switch"'
db field 2 switch

# 8. 后台保存设置
echo "保存设置"
admin_post "$config_page" "$(form_action "$(admin_get "$config_page")" plugins-edit)" \
    --data-urlencode 'display[]=post' --data-urlencode 'author=Saved Author' \
    --data-urlencode 'showPermalink[]=1' --data-urlencode 'license=by-nc-nd' \
    --data-urlencode 'notice=saved notice' --data-urlencode 'placement=auto' \
    --data-urlencode 'template='
db field 1 switch 0
post="$(get /index.php/archives/1/)"
expect "保存后作者生效" "$post" '版权属于：Saved Author</p>'
expect "保存后协议生效" "$post" '>CC BY-NC-ND 4.0</a>'
reject "保存后不再有 1.x 键名" "$(db option plugin:Copyright)" 'showURL'
expect "取消勾选兼容后不读取旧字段" "$post" '<blockquote class="content-copyright"'

# 9. 写入已发布的内容
echo "写入已发布的内容"
db sql 'DELETE FROM typecho_fields' >/dev/null
db config plugin:Copyright '{"author":"Yves X","notice":"seal notice","showPermalink":["1"],"display":["post","page"],"license":"by-sa","placement":"auto"}'
config="$(admin_get "$config_page")"
expect "设置页提示写入已发布的内容" "$config" 'copyright-seal'
expect "列出未写入的内容并可逐篇勾选" "$config" 'name="cids[]" value="1" checked'
cids="$(grep -o 'name="cids\[\]" value="[0-9]*"' <<<"$config" | grep -o '[0-9]*"$' | tr -d '"' | sed 's/^/cids[]=/' | paste -sd'&')"
admin_post "$config_page" "$(form_action "$config" seal-contents)" --data "$cids"
expect "写入后提示篇数" "$(notice)" '篇内容写入版权信息'
fields="$(db sql 'SELECT name, str_value FROM typecho_fields WHERE cid = 1 ORDER BY name')"
expect "写入作者" "$fields" '{"name":"copyrightAuthor","str_value":"Yves X"}'
expect "写入协议" "$fields" '{"name":"copyrightLicense","str_value":"by-sa"}'
expect "写入声明" "$fields" '{"name":"copyrightNotice","str_value":"seal notice"}'
reject "写入后不再提示" "$(admin_get "$config_page")" 'copyright-seal'

db config plugin:Copyright '{"author":"","notice":"","showPermalink":["1"],"display":["post","page"],"license":"by-nd","placement":"auto"}'
post="$(get /index.php/archives/1/)"
expect "已写入的作者不随全局设置改变" "$post" '版权属于：Yves X'
expect "已写入的协议不随全局设置改变" "$post" '>CC BY-SA 4.0</a>'
expect "已写入的声明不随全局设置改变" "$post" 'seal notice'
expect "记下从全局设置写入的值" "$(db sql 'SELECT str_value FROM typecho_fields WHERE cid = 1 AND name = "copyrightSealed"')" '\"copyrightAuthor\":\"Yves X\"'
config="$(admin_get "$config_page")"
expect "全局设置修改后提示更新" "$config" 'copyright-outdated'
expect "提示列出新旧值" "$config" '「Yves X」改为「typecho」'
expect "提示列出协议的变化" "$config" '「CC BY-SA 4.0」改为「CC BY-ND 4.0」'
expect "提示列出每篇内容并链接到编辑页" "$config" 'write-post.php?cid=1" target="_blank">'
expect "提示列出独立页面并链接到编辑页" "$config" 'write-page.php?cid=2" target="_blank">'
admin_post "$config_page" "$(form_action "$config" plugins-edit)" \
    --data 'author=&notice=&license=by-nd&display[]=post&display[]=page&showPermalink[]=1&placement=auto&template='
expect "保存设置时提示有内容按旧设置写入" "$(notice)" '仍是按旧设置写入的'

db field 1 copyrightNotice 'hand notice'
admin_post "$config_page" "$(form_action "$config" update-sealed)" --data 'sealed[copyrightAuthor][]=1&sealed[copyrightAuthor][]=2&sealed[copyrightAuthor][]=3&sealed[copyrightNotice][]=1&sealed[copyrightNotice][]=2&sealed[copyrightNotice][]=3'
expect "更新后提示篇数" "$(notice)" '已更新'
fields="$(db sql 'SELECT name, str_value FROM typecho_fields WHERE cid = 1 ORDER BY name')"
expect "更新为当前的作者" "$fields" '{"name":"copyrightAuthor","str_value":"typecho"}'
expect "手动修改过的值不更新" "$fields" '{"name":"copyrightNotice","str_value":"hand notice"}'
expect "未选中的协议不更新" "$fields" '{"name":"copyrightLicense","str_value":"by-sa"}'
config="$(admin_get "$config_page")"
reject "更新后不再提示作者" "$config" '「Yves X」改为'
admin_post "$config_page" "$(form_action "$config" update-sealed | sed 's/update-sealed/keep-sealed/')" --data 'sealed[copyrightLicense][]=1&sealed[copyrightLicense][]=2&sealed[copyrightLicense][]=3'
expect "保持原样不改协议" "$(db sql 'SELECT str_value FROM typecho_fields WHERE cid = 1 AND name = "copyrightLicense"')" 'by-sa'
reject "保持原样后不再提示" "$(admin_get "$config_page")" 'copyright-outdated'

editor="$(admin_get '/admin/write-post.php?cid=1')"
expect "编辑页随表单提交写入记录" "$editor" 'name="fields[copyrightSealed]"'
record="$(db sql 'SELECT str_value FROM typecho_fields WHERE cid = 1 AND name = "copyrightSealed"' | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0]["str_value"];')"
admin_post '/admin/write-post.php?cid=1' "$(form_action "$editor" contents-post-edit)" \
    --data 'cid=1' --data-urlencode 'title=Sealed post' --data-urlencode 'text=hello' --data 'do=publish' \
    --data-urlencode 'fields[copyrightAuthor]=typecho' --data-urlencode 'fields[copyrightLicense]=by-sa' \
    --data-urlencode 'fields[copyrightNotice]=hand notice' --data-urlencode "fields[copyrightSealed]=$record"
expect "重新发布后保留记录" "$(db sql 'SELECT str_value FROM typecho_fields WHERE cid = 1 AND name = "copyrightSealed"')" 'copyrightAuthor'

admin_post '/admin/write-post.php?cid=1' "$(form_action "$editor" contents-post-edit)" \
    --data 'cid=1' --data-urlencode 'title=Sealed post' --data-urlencode 'text=hello' --data 'do=publish' \
    --data-urlencode 'fields[copyrightSourceUrl]=https://example.org/original' \
    --data-urlencode 'fields[copyrightAuthor]=typecho' --data-urlencode 'fields[copyrightLicense]=by-sa' \
    --data-urlencode 'fields[copyrightNotice]=hand notice' --data-urlencode "fields[copyrightSealed]=$record"
fields="$(db sql 'SELECT name, str_value FROM typecho_fields WHERE cid = 1 ORDER BY name')"
reject "改为转载后撤下全局设置写入的作者" "$fields" '"copyrightAuthor"'
expect "改为转载后保留手动填写的声明" "$fields" '{"name":"copyrightNotice","str_value":"hand notice"}'
reject "改为转载后不再记录" "$fields" 'copyrightSealed'

editor="$(admin_get /admin/write-post.php)"
admin_post /admin/write-post.php "$(form_action "$editor" contents-post-edit)" \
    --data-urlencode 'title=Sealed on publish' --data-urlencode 'text=hello' --data 'do=publish'
cid="$(db sql "SELECT cid FROM typecho_contents WHERE title = 'Sealed on publish'" | grep -o '[0-9]\+' | head -1)"
fields="$(db sql "SELECT name, str_value FROM typecho_fields WHERE cid = $cid ORDER BY name")"
expect "发布时写入作者昵称" "$fields" '{"name":"copyrightAuthor","str_value":"typecho"}'
expect "发布时写入协议" "$fields" '{"name":"copyrightLicense","str_value":"by-nd"}'
expect "全局声明为空时写入 0" "$fields" '{"name":"copyrightNotice","str_value":"0"}'

: >"$PINGS"
editor="$(admin_get /admin/write-post.php)"
admin_post /admin/write-post.php "$(form_action "$editor" contents-post-edit)" \
    --data-urlencode 'title=Repost on publish' --data-urlencode 'text=hello' --data 'do=publish' \
    --data-urlencode 'fields[copyrightSourceUrl]=https://example.org/original' --data-urlencode 'fields[copyrightDisplay]=0'
cid="$(db sql "SELECT cid FROM typecho_contents WHERE title = 'Repost on publish'" | grep -o '[0-9]\+' | head -1)"
reject "转载发布时不写入全局设置" "$(db sql "SELECT name, str_value FROM typecho_fields WHERE cid = $cid")" '"str_value":"by-nd"'
# Typecho 1.2.0 在 PHP 8.3 以前把链接数组直接交给 curl，任何 pingback 都发不出去，1.2.1 已修复
if [[ "$VERSION" != 1.2.0 ]] || php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
    expect "不显示版权信息的转载，发布时也由 Typecho 向原文链接发送 pingback" "$(cat "$PINGS")" '"https:\/\/example.org\/original"'

    : >"$PINGS"
    editor="$(admin_get /admin/write-page.php)"
    admin_post /admin/write-page.php "$(form_action "$editor" contents-page-edit)" \
        --data-urlencode 'title=Repost page' --data-urlencode 'text=hello' --data 'do=publish' \
        --data-urlencode 'fields[copyrightSourceUrl]=https://example.org/page'
    expect "转载的独立页面发布时同样发送 pingback" "$(cat "$PINGS")" '"https:\/\/example.org\/page"'
fi
db sql 'DELETE FROM typecho_fields' >/dev/null

# 10. 全新启用
echo "全新启用"
plugin_action deactivate
plugin_action activate
post="$(get /index.php/archives/1/)"
expect "默认在文章显示，作者取文章作者" "$post" '版权属于：typecho</p>'
expect "全新启用不读取旧字段" "$post" '<blockquote class="content-copyright"'
db field 1 switch

# 只追究插件自身的问题；旧版 Typecho 在新版 PHP 上的弃用提示另行列出
if grep -q 'plugins/Copyright/' "$ERRORS"; then
    fail "插件无 PHP 警告和错误"
    grep 'plugins/Copyright/' "$ERRORS" | sort | uniq -c | sed 's/^/    /'
else
    pass "插件无 PHP 警告和错误"
fi
if [[ -s "$ERRORS" ]] && grep -qv 'plugins/Copyright/' "$ERRORS"; then
    echo "  Typecho 自身的提示："
    grep -v 'plugins/Copyright/' "$ERRORS" | sed 's/^\[[^]]*\] //' | sort | uniq -c | head -20 | sed 's/^/    /'
fi

if [[ $failures -gt 0 ]]; then
    echo "$failures 项失败"
    exit 1
fi
echo "全部通过"
