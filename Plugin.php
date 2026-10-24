<?php

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Copyright for Typecho
 *
 * @package Copyright
 * @author  Yves X
 * @version 1.0.5
 * @link https://github.com/Yves-X/Copyright-for-Typecho
 */
class Copyright_Plugin implements Typecho_Plugin_Interface {
    /**
     * 启用插件
     *
     * @access public
     * @return void
     */
    public static function activate() {
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('Copyright_Plugin', 'Copyright');
    }

    /**
     * 禁用插件
     *
     * @access public
     * @return void
     */
    public static function deactivate() {
    }

    /**
     * 全局设置
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form) {
        echo '<p>' . _t('欢迎使用 Typecho 版权插件。') . '</p>';
        echo '<p>' . _t('此插件帮助你设置文章与独立页面的版权声明，它会附在内容末尾。你也可以对特定某篇内容设置版权信息。') . '</p>';
        echo '<p>' . _t('版权信息借助插件与 Typecho 的自定义字段功能实现，只与插件或特定内容关联，而不会修改其内容本身，也不会在数据库中与文本混同。') . '</p>';
        echo '<hr />';
        echo '<p>' . _t('此处为<strong>全局设置</strong>。') . '</p>';
        echo '<p>' . _t('如需对特定某篇内容设置版权信息，请参阅<a href="%s">详细说明</a>。', 'https://github.com/Yves-X/Copyright-for-Typecho/tree/1.x') . '</p>';
        echo '<p>' . _t('特定设置的优先级始终高于全局设置，所以如果你给某篇文章单独设置了版权信息，你所设置的部分将会覆盖全局设置。') . '</p>';
        echo '<hr />';

        $author = new Typecho_Widget_Helper_Form_Element_Text('author', NULL, '', _t('作者'), _t('留空则不显示。'));
        $form->addInput($author);
        $notice = new Typecho_Widget_Helper_Form_Element_Text('notice', NULL, _t('转载时须注明出处及本声明'), _t('声明'), _t('可以使用 HTML。'));
        $form->addInput($notice);
        // 多选框的默认值必须是数组，否则 Typecho 1.3 保存时会丢掉勾选的值
        $showURL = new Typecho_Widget_Helper_Form_Element_Checkbox('showURL', array(1 => _t('显示本文链接')), array(1), NULL, _t('填写了原文链接的转载文章，总是显示原文链接。'));
        $form->addInput($showURL);
        $showOnPost = new Typecho_Widget_Helper_Form_Element_Checkbox('showOnPost', array(1 => _t('在文章显示')), array(1));
        $form->addInput($showOnPost);
        $showOnPage = new Typecho_Widget_Helper_Form_Element_Checkbox('showOnPage', array(1 => _t('在独立页面显示')), array());
        $form->addInput($showOnPage);
    }

    /**
     * 个人设置，本插件没有
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {
    }

    /**
     * 在正文末尾追加版权信息
     *
     * Typecho 会把前一个插件处理后的正文作为最后一个参数传入，第一个插件收到的是 NULL。
     *
     * @access public
     * @param string $content 正文
     * @param Widget_Abstract_Contents $widget 内容
     * @param string|null $lastResult 前一个插件处理后的正文
     * @return string
     */
    public static function Copyright($content, $widget, $lastResult = NULL) {
        $content = empty($lastResult) ? $content : $lastResult;
        $copyright = self::apply($widget);

        return $copyright === NULL ? $content : $content . self::render($copyright);
    }

    /**
     * 合并全局设置和本篇的自定义字段，不显示时返回 NULL
     *
     * 自定义字段留空表示沿用全局设置，填 0 表示不显示这一项。
     *
     * @param Widget_Abstract_Contents $widget
     * @return array|null
     */
    private static function apply($widget) {
        // 只在文章和独立页面的详情页显示，首页、分类等列表页不显示。
        // 主题或其他插件也可能输出内容，只有 Widget_Archive 才有 is()
        if (!method_exists($widget, 'is') || !$widget->is('single')) {
            return NULL;
        }

        $type = $widget->parameter->type;
        if ($type != 'post' && $type != 'page') {
            return NULL;
        }

        $options = Typecho_Widget::widget('Widget_Options')->plugin('Copyright');
        $fields = $widget->fields;

        $switch = (string) $fields->switch;
        if ($switch !== '') {
            $isEnabled = $switch !== '0';
        } else {
            $isEnabled = !empty($type == 'post' ? $options->showOnPost : $options->showOnPage);
        }

        if (!$isEnabled) {
            return NULL;
        }

        $url = (string) $fields->url;
        $isOriginal = $url === '';
        if ($isOriginal) {
            $url = empty($options->showURL) ? '' : $widget->permalink;
        }

        return array(
            'author'      => self::override($fields->author, $options->author),
            'url'         => self::isWebUrl($url) ? $url : '',
            'is_original' => $isOriginal,
            'notice'      => self::override($fields->notice, $options->notice),
        );
    }

    /**
     * 自定义字段留空时沿用全局设置，填 0 时不显示
     *
     * @param string|null $field
     * @param string|null $global
     * @return string
     */
    private static function override($field, $global) {
        $field = (string) $field;
        if ($field === '') {
            return (string) $global;
        }

        return $field === '0' ? '' : $field;
    }

    /**
     * 只接受 http 和 https 链接，以免 javascript: 之类的地址被写进页面
     *
     * @param string $url
     * @return bool
     */
    private static function isWebUrl($url) {
        return (bool) preg_match('/^https?:\/\//i', $url);
    }

    /**
     * @param string $text
     * @return string
     */
    private static function escape($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 输出结构与之前的版本一致。作者和声明按 HTML 原样输出，链接会被转义
     *
     * @param array $copyright
     * @return string
     */
    private static function render(array $copyright) {
        $html = '';

        if ($copyright['author'] !== '') {
            $html .= '<p class="content-copyright">' . _t('版权属于：') . $copyright['author'] . '</p>';
        }

        if ($copyright['url'] !== '') {
            $url = self::escape($copyright['url']);
            if ($copyright['is_original']) {
                $html .= '<p class="content-copyright">' . _t('本文链接：') . '<a class="content-copyright" href="' . $url . '">' . $url . '</a></p>';
            } else {
                $html .= '<p class="content-copyright">' . _t('原文链接：') . '<a class="content-copyright" target="_blank" rel="noopener" href="' . $url . '">' . $url . '</a></p>';
            }
        }

        if ($copyright['notice'] !== '') {
            $html .= '<p class="content-copyright">' . $copyright['notice'] . '</p>';
        }

        if ($html === '') {
            return '';
        }

        return '<hr class="content-copyright" style="margin-top:50px" /><blockquote class="content-copyright" style="font-style:normal">' . $html . '</blockquote>';
    }
}
