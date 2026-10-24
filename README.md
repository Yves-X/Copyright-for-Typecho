# Copyright for Typecho

[![Test](https://github.com/Yves-X/Copyright-for-Typecho/actions/workflows/test.yml/badge.svg)](https://github.com/Yves-X/Copyright-for-Typecho/actions/workflows/test.yml)

这是一个 [Typecho](https://github.com/typecho/typecho) 插件，利用自定义字段功能灵活地在文章或独立页面尾部显示版权小尾巴。

就像这样：

> 版权属于：Yves X
>
> 原文链接：https://github.com/Yves-X/Copyright-for-Typecho
>
> 许可协议：[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
>
> 转载时须注明出处及本声明

[English](#english)

## Features

- **利用 Typecho 原生的自定义字段实现。** 版权信息跟随内容，本应如此。

- **轻巧且灵活。** 不新增表，不自带复杂样式，默认就能融入你的博客，同时又能方便地控制其输出内容与外观。

- **平衡方便与稳健。** 一次设置、个别覆盖、实时预览、固化字段、通告转载，以一种恰到好处的方式维护版权信息。

- **内建的许可协议推荐。** 原地回答几个问题就能得到许可协议推荐，不必打开第二个窗口。

## 安装

需要 Typecho 1.2 及以上、PHP 7.4 及以上。（Typecho 1.0 和 1.1 请使用 [1.x 分支](https://github.com/Yves-X/Copyright-for-Typecho/tree/1.x)。）

1. 从 [Releases](https://github.com/Yves-X/Copyright-for-Typecho/releases) 下载 `Copyright-v*.zip`
1. 解压至 Typecho 的 `usr/plugins/` 下，得到 `usr/plugins/Copyright/`
1. 在后台「控制台 → 插件」中启用，然后点「设置」

也可以用 Git 安装，注意目录名必须是 `Copyright`：

```bash
# Initially open the root directory of your Typecho, then
cd usr/plugins/
git clone https://github.com/Yves-X/Copyright-for-Typecho.git Copyright
```

### 从 1.x 升级

**直接覆盖、不要禁用插件**，否则 Typecho 会删掉你的全局设置。

你的插件应该立刻就能够正常工作，但你还需要：

#### 迁移 1.x 旧字段

插件的 2.0 版本调整了自定义字段名，插件的设置页面会列出他们，以便你一键迁移。但这些字段名也可能被其他插件或主题所使用（这正是需要迁移字段的原因），因此你可能需要先备份数据库再迁移，并确认主题和插件都正常。

#### 固化已发布内容的版权信息

插件的 2.0 会在发布时把全局的版权信息固化到内容的自定义字段，以免全局设置的变更导致版权信息的意外变化。插件的设置页面会提示你写入历史文章和页面的版权信息。

#### 检查输出

插件的 2.0 版本改变了一些行为，包括：

- 填写了原文链接的转载内容不再默认套用全局的作者和版权声明，除非单独填写。

- 去掉了 `hr` 和 `blockquote` 的内联样式，完全由主题和 `content-copyright` 类可能存在的自定义样式决定。

因此，在配置没有任何变化的情况下，2.0 以后的外观与内容可能与之前不同。请在升级后抽查一个样式是否符合预期，以及检查每一篇已有的转载内容的版权声明是否正确。

## 设置

你在插件管理中看到的是**全局设置**。编辑文章或独立页面时，「自定义字段」里会多出`版权信息`、`内容来源`、`版权作者`、`许可协议`和`版权声明`，用于单独设置特定内容的版权信息、覆盖全局设置。

相较于过去，现在你不再需要知道这些字段的数据格式与内部逻辑。自定义字段已经提供了易用的表单与直观的预览。

当你调整全局设置，例如打算更改默认的许可协议，并不会自动应用到已发布的内容上。插件的设置页面会提示你历史内容的版权变更情况，等待你的确认。

## 示例

本插件的「灵活」意为旨在用简单的配置适用于绝大多数情形，让版权信息自然地跟随内容，并减少重复填写。

让我们来看一个有意复杂化的示例：

假设你的全局设置如下：

- 作者：Collin
- 许可协议：不使用协议
- 声明：转载时须注明出处及本声明
- 显示本文链接：开
- 显示位置：不勾选，因为不想得到未经审视的默认输出

这样一来，插件不会在任何地方默认输出版权信息。

现在你有一篇原创文章想要防御性地发表：你不希望有人转载他，如果被抓取，你希望留下链接，但不希望留下署名。这其实与常见情形的推断逻辑有多处不符。

在这篇文章的自定义字段里配置版权信息，你同样能够实现：

> 本文链接：https://www.yvesx.com/archives/copyright-for-typecho-v2
>
> 禁止转载

## 定制

插件不带任何样式，版权信息会遵循主题的预设，像是内容的一部分。同时，每个元素都有 `.content-copyright` 的 CSS 类作为钩子，以便你自定义。你也可以直接在插件的设置页面注入样式。

如果你想要换掉整个结构，可以在设置里填一段 HTML 模板，比如：

```html
<p>{title} 由 {author} 发表于 {site}，采用 {license} 许可。</p>
```

可用的变量有 `{author}` `{link}` `{linkUrl}` `{linkLabel}` `{license}` `{licenseName}` `{licenseSummary}` `{licenseUrl}` `{notice}` `{title}` `{permalink}` `{date}` `{site}`。

如果你想把版权信息放在别处，把「输出方式」改成「由主题调用」，再在主题的相应位置如 `post.php` 和 `page.php` 里写：

```php
<?php \TypechoPlugin\Copyright\Plugin::render($this); ?>
```

## 致谢

[神代綺凜](https://github.com/Tsuk1ko) · [黎明余光](https://github.com/LiMingYuGuang) · [tanluxiami](https://github.com/tanluxiami) · [mikusaa](https://github.com/mikusaa)

以及所有 Star、使用、分享过这个小插件的人。

在发布这个插件时我还非常年轻，谢谢你们在手写代码的年代参与这个稚拙的项目，那是美好、令人怀念却又已逝的旧时光。

## English

Nowadays, an LLM can read this to you in any language—and that is all.

## 许可

[GPL-3.0](LICENSE)

项目维护者支持其观点并愿其广为人知：

[![Badge](https://img.shields.io/badge/link-996.icu-red.svg)](https://996.icu)
[![Badge](https://img.shields.io/badge/link-NPL%20(The%20996%20Prohibited%20License)-red.svg)](https://github.com/996icu/996.ICU/blob/master/LICENSE)
