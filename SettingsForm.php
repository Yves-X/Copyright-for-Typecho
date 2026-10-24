<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Typecho\Common;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Layout;
use Widget\Options;
use Widget\Security;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 插件设置页
 *
 * 表单项的名字就是保存的键名，由 Settings 读取。
 */
final class SettingsForm
{
    public static function build(Form $form): void
    {
        $settings = Settings::current();

        echo Assets::tags();
        echo '<p>' . _t('这里是全局的版权设置。对于特定的文章和页面，你可以在自定义字段中单独配置，以覆盖全局设置。') . '</p>';
        echo '<p>' . _t('作者、许可协议和声明会在发布时写入该内容，之后修改这里只影响新发布的内容。') . '</p>';
        // Typecho 启用插件和保存设置时也会构建这个表单，这些检查要遍历全部内容，只在打开设置页时进行
        if (self::isSettingsPage()) {
            $legacyNotice = self::migrationNotice($settings);
            echo $legacyNotice . self::sealNotice($settings, $legacyNotice !== '') . self::outdatedNotice($settings);
        }

        $form->addInput(new Checkbox(
            'display',
            ['post' => _t('文章'), 'page' => _t('独立页面'), 'feed' => _t('聚合')],
            $settings->display,
            _t('显示位置'),
            _t('你可以通过自定义字段单独开启或关闭。')
        ));

        $form->addInput(new Text(
            'author',
            null,
            $settings->author,
            _t('作者'),
            _t('留空时显示该文章或页面的作者昵称。')
        ));

        $form->addInput(new Checkbox(
            'showPermalink',
            ['1' => _t('显示本文链接')],
            $settings->showPermalink ? ['1'] : [],
            _t('链接'),
            _t('（转载的内容总是显示原文链接。）')
        ));

        $form->addInput(new Select(
            'license',
            ['' => _t('不使用协议')] + LicenseType::options(),
            $settings->license !== null ? $settings->license->value : '',
            _t('许可协议'),
            _t('选择一个成熟的共享协议，或是不使用协议。（转载的内容不会套用协议。）')
        ));
        $chooser = new Layout('ul', ['class' => 'typecho-option']);
        $chooser->html('<li>' . LicenseChooser::html('license', _t('已填入，保存设置后生效。')) . '</li>');
        $form->addItem($chooser);

        $notice = new Textarea(
            'notice',
            null,
            $settings->notice,
            _t('声明'),
            _t('简明地表达你的要求，作为声明或协议的补充。例如「转载须注明出处」。可以使用 a、strong、em、code、br 等行内标签。')
        );
        $notice->setInputsAttribute('rows', '2');
        $form->addInput($notice);

        $form->addInput(new Radio(
            'placement',
            ['auto' => _t('自动追加到正文末尾'), 'manual' => _t('由主题调用')],
            $settings->appendToContent ? 'auto' : 'manual',
            _t('输出方式'),
            _t(
                '选择「由主题调用」时，在主题的 post.php 与 page.php 中需要显示的位置写入 %s。',
                '<code>&lt;?php \TypechoPlugin\Copyright\Plugin::render($this); ?&gt;</code>'
            )
        ));

        $variables = array_map(static fn(string $name): string => "<code>{{$name}}</code>", Renderer::TEMPLATE_VARIABLES);
        $template = new Textarea(
            'template',
            null,
            $settings->template,
            _t('自定义内容模版'),
            _t('版权信息的 HTML 结构，留空使用默认结构。可用变量：%s。', implode(' ', $variables))
        );
        $template->setInputsAttribute('rows', '4');
        $template->setInputsAttribute('class', 'w-100 mono');
        $form->addInput($template);

        $style = new Textarea(
            'style',
            null,
            $settings->style,
            _t('自定义样式'),
            _t('以内联的 %s 输出到显示版权信息的页面，留空不输出。默认结构的元素都带有 %s 类。', '<code>&lt;style&gt;</code>', '<code>content-copyright</code>')
        );
        $style->setInputsAttribute('rows', '4');
        $style->setInputsAttribute('class', 'w-100 mono');
        $style->setInputsAttribute('placeholder', 'blockquote.content-copyright { font-style: normal; }');
        $form->addInput($style);

        $preview = new Layout('ul', ['class' => 'typecho-option']);
        $preview->html('<li>' . Preview::container('settings') . '</li>');
        $form->addItem($preview);

        $form->addInput(new Checkbox(
            'legacyFields',
            ['1' => _t('尝试读取 1.x 旧字段（%s）', implode(_t('、'), Field::LEGACY_NAMES))],
            $settings->readLegacyFields ? ['1'] : [],
            _t('兼容 1.x 旧字段'),
            _t('插件的 1.x 版本将版权信息存储在这些旧字段里。从 1.x 升级的站点默认开启，以便历史内容仍能显示版权信息。完成旧字段迁移即可关闭，以免将主题或其他插件写入的信息误作版权信息。')
        ));
    }

    /**
     * 还有 1.x 的自定义字段在使用时，列出用量和示例，由用户逐个确认迁移
     */
    private static function migrationNotice(Settings $settings): string
    {
        $legacyFields = Upgrade::legacyFields();
        if ($legacyFields === []) {
            return '';
        }

        // 迁移由后台操作完成，只覆盖文件升级时他可能还没有注册
        Upgrade::registerIfOutdated();

        $items = '';
        foreach ($legacyFields as $legacy => $usage) {
            $values = array_map(
                static fn(array $content): string => $content['value'] === '' ? _t('（空）') : Common::subStr($content['value'], 0, 30, '…'),
                $usage['contents']
            );

            $items .= '<li><label><input type="checkbox" name="legacy[]" value="' . Html::escape($legacy) . '" /> '
                . _t('%s 改为 %s', '<code>' . Html::escape($legacy) . '</code>', "<code>{$usage['field']}</code>")
                . '</label>' . self::contentList($usage['contents'], _t('%d 处', count($usage['contents'])), $values) . '</li>';
        }

        $state = $settings->readLegacyFields ? '' : _t('，但「兼容 1.x 旧字段」已关闭，目前不会读取');
        $action = Security::alloc()->getIndex('/action/' . Action::NAME . '?do=migrate-fields');

        return '<form class="copyright-panel copyright-migration" method="post" action="' . Html::escape($action) . '">'
            . '<h4>' . _t('迁移 1.x 旧字段') . '</h4>'
            . '<p>' . _t('在过去，插件通常在自己的表里存储数据，自定义字段很少被使用。而现在，越来越多的插件和主题共享着自定义字段，因此本插件在 2.0 以后使用带有 copyright 前缀的新字段，以免和其他插件或主题的同名字段相冲突。') . '</p>'
            . '<p>' . _t('检测到下面这些 1.x 旧字段还在使用%s。', $state) . '</p>'
            . '<p>' . _t('迁移操作会把 1.x 旧字段升级到新字段，应尽早实施。（如果新字段已有不同的值，将保留新字段。）<strong>如果主题或其他插件也在使用相同的字段名，将导致他们无法继续读取相应信息</strong>，建议先备份数据库。') . '</p>'
            . '<ul>' . $items . '</ul>'
            . '<button type="submit" class="btn btn-s">' . _t('迁移选中的字段') . '</button>'
            . '</form>';
    }

    /**
     * 还有已发布的内容没有写入版权信息时，由用户确认后一次性写入
     */
    private static function sealNotice(Settings $settings, bool $hasLegacyFields): string
    {
        $pending = Seal::pending($settings);
        if ($pending === []) {
            return '';
        }

        Upgrade::registerIfOutdated();
        $action = Security::alloc()->getIndex('/action/' . Action::NAME . '?do=seal-contents');
        $order = $hasLegacyFields ? _t('建议先迁移上面的 1.x 旧字段。') : '';

        return '<form class="copyright-panel copyright-seal" method="post" action="' . Html::escape($action) . '">'
            . '<h4>' . _t('为已发布的内容写入版权信息') . '</h4>'
            . '<p>' . _t('现在，版权信息会在内容发布时固化，以免全局配置的变更导致已发布内容的版权信息被意外修改。') . '</p>'
            . '<p>' . _t('检测到下面这些历史文章和页面还没有写入版权信息。请调整全局设置或他们的自定义字段，确认版权信息无误后写入。') . $order . '</p>'
            . self::contentList($pending, _t('%d 篇', count($pending)), [], 'cids[]', true)
            . '<button type="submit" class="btn btn-s">' . _t('写入选中的内容') . '</button>'
            . '</form>';
    }

    /**
     * 全局设置修改后，列出仍是按旧设置写入的值，由用户选择更新或保持原样
     */
    private static function outdatedNotice(Settings $settings): string
    {
        $outdated = Seal::outdated($settings);
        if ($outdated === []) {
            return '';
        }

        $labels = [Field::AUTHOR => _t('作者'), Field::LICENSE => _t('许可协议'), Field::NOTICE => _t('声明')];
        $items = '';
        foreach (array_intersect_key($labels, $outdated) as $name => $label) {
            $changes = [];
            foreach ($outdated[$name] as $entry) {
                $change = _t('「%s」改为「%s」', self::sealedValue($name, $entry['old']), self::sealedValue($name, $entry['new']));
                $changes[$change][] = $entry;
            }

            $lines = '';
            foreach ($changes as $change => $contents) {
                $lines .= self::contentList(
                    $contents,
                    $change . _t('：%d 篇', count($contents)),
                    [],
                    'sealed[' . $name . '][]',
                    $name !== Field::LICENSE
                );
            }

            // 协议默认不勾选，避免顺手改掉已发布内容的协议
            $isChecked = $name !== Field::LICENSE ? ' checked' : '';
            $items .= '<li data-toggle-scope><label><input type="checkbox" data-toggle-all' . $isChecked . ' /> '
                . $label . '</label>' . $lines . '</li>';
        }

        $licenseNote = isset($outdated[Field::LICENSE])
            ? '<p>' . _t('在法理上，许可协议一经发布便不可撤回：已经按旧协议获得内容的人仍可按旧协议使用，更新只影响之后获得内容的人。') . '</p>'
            : '';

        Upgrade::registerIfOutdated();
        $url = static fn(string $do): string => Html::escape(Security::alloc()->getIndex('/action/' . Action::NAME . '?do=' . $do));

        return '<form class="copyright-panel copyright-outdated" method="post" action="' . $url('update-sealed') . '">'
            . '<h4>' . _t('更新已写入的版权信息') . '</h4>'
            . '<p>' . _t('全局设置修改后，下面这些内容仍保存着发布时写入的旧设置。手动修改过的内容不在此列。') . '</p>'
            . '<ul>' . $items . '</ul>'
            . $licenseNote
            . '<button type="submit" class="btn btn-s">' . _t('更新选中的项') . '</button> '
            . '<button type="submit" class="btn btn-s" formaction="' . $url('keep-sealed') . '">' . _t('保持原样') . '</button>'
            . '<p class="description">' . _t('选择保持原样后，这些内容不再提示。') . '</p>'
            . '</form>';
    }

    /**
     * 可展开的内容列表，标题链接到编辑页，方便逐篇确认；给出 $checkbox 时每篇前有选框，摘要中可以全选
     *
     * @param list<array{cid: int, type: string, title: string}> $contents
     * @param string $summary 摘要 HTML
     * @param list<string> $notes 每篇后面附带的说明，未转义
     * @param string|null $checkbox 选框的名字，值为 cid
     */
    private static function contentList(
        array $contents,
        string $summary,
        array $notes = [],
        ?string $checkbox = null,
        bool $isChecked = false
    ): string {
        $checked = $isChecked ? ' checked' : '';
        $adminUrl = Options::alloc()->adminUrl;
        $items = '';
        foreach ($contents as $i => $content) {
            $editor = strpos($content['type'], 'page') === 0 ? 'write-page.php' : 'write-post.php';
            $url = Common::url($editor . '?cid=' . $content['cid'], $adminUrl);
            $title = $content['title'] !== '' ? $content['title'] : _t('（无标题）');
            $note = isset($notes[$i]) ? _t('：%s', Html::escape($notes[$i])) : '';

            $link = '<a href="' . Html::escape($url) . '" target="_blank">' . Html::escape($title) . '</a>' . $note;
            $items .= $checkbox === null
                ? '<li>' . $link . '</li>'
                : '<li><input type="checkbox" name="' . Html::escape($checkbox) . '" value="' . $content['cid'] . '"' . $checked . ' /> ' . $link . '</li>';
        }

        if ($checkbox === null) {
            return '<details class="copyright-contents"><summary>' . $summary . '</summary><ul>' . $items . '</ul></details>';
        }

        return '<details class="copyright-contents" data-toggle-scope><summary>' . $summary . '</summary>'
            . '<label class="copyright-contents-all"><input type="checkbox" data-toggle-all' . $checked . ' /> ' . _t('全选') . '</label>'
            . '<ul class="copyright-contents-checks">' . $items . '</ul></details>';
    }

    private static function isSettingsPage(): bool
    {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'options-plugin.php';
    }

    /**
     * 提示中显示的值
     */
    private static function sealedValue(string $name, string $value): string
    {
        if ($value === Field::OFF) {
            return _t('不显示');
        }

        if ($name === Field::LICENSE) {
            $license = LicenseType::tryFrom($value);

            return $license !== null ? $license->title() : Html::escape($value);
        }

        return Html::escape(Common::subStr(strip_tags($value), 0, 30, '…'));
    }
}
