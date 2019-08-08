<?php

if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * Copyright for Typecho
 *
 * @package Copyright
 * @author  Yves X
 * @version 1.0.4
 * @link https://github.com/Yves-X/Copyright-for-Typecho
 */

class Copyright_Plugin implements Typecho_Plugin_Interface {
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate() {
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('Copyright_Plugin', 'Copyright');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate() {
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form) {
        echo '<p>欢迎使用 Typecho 版权插件。</p>';
        echo '<p>此插件帮助你设置文章与独立页面的版权声明，它会附在内容末尾。你也可以对特定某篇内容设置版权信息。</p>';
        echo '<p>版权信息借助插件与 Typecho 的自定义字段功能实现，只与插件或特定内容关联，而不会修改其内容本身，也不会在数据库中与文本混同。</p>';
        echo '<hr />';
        echo '<p>此处为<b>全局设置</b></p>';
        echo '<p>如需对特定某篇内容设置版权信息，请参阅<b><a href="https://github.com/Yves-X/Copyright-for-Typecho">详细说明</a></b>';
        echo '<p>特定设置的优先级始终高于全局设置，所以如果你给某篇文章单独设置了版权信息，你所设置的部分将会覆盖全局设置</p>';
        echo '<hr />';
        echo '作者曾有很长一段时间没有维护此插件，这期间它随 Typecho 1.1 更新而失效。感谢该网友修复了此插件，使得它可以在 Typecho 1.1 下继续工作：<a href="https://lolico.moe" target="_blank">神代綺凜</a>';
        echo '<hr />';
        $author = new Typecho_Widget_Helper_Form_Element_Text('author', NULL, _t('作者名称'), _t('作者'));
        $form->addInput($author);
        $notice = new Typecho_Widget_Helper_Form_Element_Text('notice', NULL, _t('转载时须注明出处及本声明'), _t('声明'));
        $form->addInput($notice);
        $showURL = new Typecho_Widget_Helper_Form_Element_Checkbox('showURL', array(1 => _t('显示原（本）文链接')), NULL, NULL, NULL);
        $form->addInput($showURL);
        $showOnPost = new Typecho_Widget_Helper_Form_Element_Checkbox('showOnPost', array(1 => _t('在文章显示')), NULL, NULL, NULL);
        $form->addInput($showOnPost);
        $showOnPage = new Typecho_Widget_Helper_Form_Element_Checkbox('showOnPage', array(1 => _t('在独立页面显示')), NULL, NULL, NULL);
        $form->addInput($showOnPage);
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {
    }

    /**
     * 插件实现方法
     *
     * @access public
     * @return void
     */

    public static function Copyright($content, $widget, $lastResult) {
        $content = empty($lastResult) ? $content : $lastResult;
        $cr = self::apply($widget);
        $cr_html = self::render($cr);
        $content = $content . $cr_html;
        return $content;
    }

    private static function globalCopyright($widget) {
        $cr = array('show_on_post' => '', 'show_on_page' => '', 'show_url' => '', 'author' => '', 'url' => '', 'notice' => '');
        $cr['show_on_post'] = Typecho_Widget::widget('Widget_Options')->plugin('Copyright')->showOnPost;
        $cr['show_on_page'] = Typecho_Widget::widget('Widget_Options')->plugin('Copyright')->showOnPage;
        $cr['show_url'] = Typecho_Widget::widget('Widget_Options')->plugin('Copyright')->showURL[0];
        $cr['author'] = Typecho_Widget::widget('Widget_Options')->plugin('Copyright')->author;
        $cr['url'] = Typecho_Widget::widget('Widget_Options')->plugin('Copyright')->url;
        $cr['notice'] = Typecho_Widget::widget('Widget_Options')->plugin('Copyright')->notice;
        return $cr;
    }

    private static function localCopyright($widget) {
        $cr = array('switch_on' => '', 'author' => '', 'url' => '', 'notice' => '');
        $cr['switch_on'] = $widget->fields->switch;
        $cr['author'] = $widget->fields->author;
        $cr['url'] = $widget->fields->url;
        $cr['notice'] = $widget->fields->notice;
        return $cr;
    }

    private static function apply($widget) {
        $gcr = self::globalCopyright($widget);
        $lcr = self::localCopyright($widget);
        $cr = array('is_enable' => '', 'is_original' => '', 'author' => '', 'url' => '', 'notice' => '');
        if ($widget->is('single')) {
            $cr['is_enable'] = 1;
        }
        if ($widget->parameter->type == 'post' && $gcr['show_on_post'] == 0) {
            $cr['is_enable'] = 0;
        }
        if ($widget->parameter->type == 'page' && $gcr['show_on_page'] == 0) {
            $cr['is_enable'] = 0;
        }
        if ($lcr['switch_on'] != '') {
            $cr['is_enable'] = $lcr['switch_on'];
        }
        if ($gcr['show_url'] == 0) {
            $cr['url'] = 0;
        }
        $cr['url'] = $lcr['url'] != '' ? $lcr['url'] : $gcr['url'];
        if ($gcr['show_url'] == 1 && $lcr['url'] == '') {
            $cr['is_original'] = 1;
            $cr['url'] = $widget->permalink;
        }
        $cr['author'] = $lcr['author'] != '' ? $lcr['author'] : $gcr['author'];
        $cr['notice'] = $lcr['notice'] != '' ? $lcr['notice'] : $gcr['notice'];
        return $cr;
    }

    private static function render($cr) {
        $copyright_html = '';
        $t_author = '';
        $t_notice = '';
        $t_url = '';
        if ($cr['is_enable']) {
            if ($cr['author']) {
                $t_author = '<p class="content-copyright">版权属于：' . $cr['author'] . '</p>';
            }
            if ($cr['url']) {
                if ($cr['is_original']) {
                    $t_url = '<p class="content-copyright">本文链接：<a class="content-copyright" href="' . $cr['url'] . '">' . $cr['url'] . '</a></p>';
                } else {
                    $t_url = '<p class="content-copyright">原文链接：<a class="content-copyright" target="_blank" href="' . $cr['url'] . '">' . $cr['url'] . '</a></p>';
                }
            }
            if ($cr['notice']) {
                $t_notice = '<p class="content-copyright">' . $cr['notice'] . '</p>';
            }
            $copyright_html = '<hr class="content-copyright" style="margin-top:50px" /><blockquote class="content-copyright" style="font-style:normal">' . $t_author . $t_url . $t_notice . '</blockquote>';
        }
        return $copyright_html;
    }

}
