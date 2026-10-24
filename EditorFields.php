<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Typecho\Common;
use Typecho\Widget\Helper\Form\Element;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Layout;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 编辑页中的版权字段
 *
 * 留空的选项都标出当前的全局设置，作者不用记字段名，也知道留空会得到什么。
 */
final class EditorFields
{
    /**
     * @param string $type post 或 page
     */
    public static function addTo(Layout $layout, string $type): void
    {
        $settings = Settings::current();

        $layout->addItem(new Select(
            Field::DISPLAY,
            [
                ''         => _t('套用全局设置（%s）', $settings->displaysOn($type) ? _t('显示') : _t('不显示')),
                Field::ON  => _t('显示'),
                Field::OFF => _t('不显示'),
            ],
            '',
            _t('版权信息')
        ));

        // 原创还是转载只取决于有没有原文链接，单选框不是字段，由脚本放到输入框前并据此显示或清空原文链接
        $source = self::wide(new Text(
            Field::SOURCE_URL,
            null,
            null,
            _t('内容来源'),
            '<span class="copyright-origin">'
                . '<label><input type="radio" name="copyright-origin" value="original" /> ' . _t('原创') . '</label> '
                . '<label><input type="radio" name="copyright-origin" value="repost" /> ' . _t('转载') . '</label>'
                . '</span>'
        ));
        $source->setInputsAttribute('placeholder', _t('原文链接，以 http:// 或 https:// 开头'));
        $layout->addItem($source);

        $siteAuthor = $settings->author !== '' ? $settings->author : _t('作者的昵称');
        $layout->addItem(self::wide(new Text(
            Field::AUTHOR,
            null,
            null,
            _t('版权作者'),
            self::mode(Field::AUTHOR, $siteAuthor)
        )));

        $siteLicense = $settings->license !== null ? $settings->license->title() : _t('不使用协议');
        // Typecho 不转义下拉框的选项
        $license = new Select(
            Field::LICENSE,
            ['' => Html::escape(_t('套用全局设置（%s）', $siteLicense)), Field::OFF => _t('不使用协议')]
                + array_map([Html::class, 'escape'], LicenseType::options()),
            '',
            _t('许可协议'),
            Assets::tags()
                . LicenseChooser::html('fields[' . Field::LICENSE . ']', _t('已填入，保存后生效。'))
        );
        $layout->addItem($license);

        $siteNotice = Common::subStr(strip_tags($settings->notice), 0, 30, '…');
        $layout->addItem(self::wide(new Text(
            Field::NOTICE,
            null,
            null,
            _t('版权声明'),
            self::mode(Field::NOTICE, $siteNotice !== '' ? $siteNotice : _t('无'))
                . Preview::container('editor', $type)
        )));

        // 发布时写入的记录要随编辑页一起提交，否则保存时 Typecho 会删掉没有提交的字段；这一行由样式隐藏
        $layout->addItem(new Hidden(Field::SEALED, null, null, ''));
    }

    /**
     * 套用全局设置、自定义、不显示三种方式，分别对应字段留空、填写和填 0
     *
     * 单选项不是字段，由脚本放到输入框前，并据此填写或隐藏输入框；转载时没有「套用全局设置」。
     */
    private static function mode(string $field, string $siteValue): string
    {
        $name = 'copyright-mode-' . $field;
        $radio = static fn(string $value, string $label): string => '<label><input type="radio" name="' . $name
            . '" value="' . $value . '" /> ' . $label . '</label> ';

        return '<span class="copyright-mode" data-for="fields[' . $field . ']" hidden>'
            . $radio('inherit', Html::escape(_t('套用全局设置（%s）', $siteValue)))
            . $radio('custom', _t('自定义'))
            . $radio('off', _t('不显示'))
            . '</span>';
    }

    private static function wide(Element $element): Element
    {
        $element->setInputsAttribute('class', 'w-100');

        return $element;
    }
}
