<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 许可协议的辅助选择，设置页和编辑页共用
 */
final class LicenseChooser
{
    /**
     * 按 Creative Commons 协议选择器（https://creativecommons.org/chooser/）的流程提问，
     * 由脚本推荐协议并在用户选用后填入名为 $select 的下拉框；保存的始终是下拉框的值
     */
    public static function html(string $select, string $appliedNote): string
    {
        $deeds = [];
        foreach (LicenseType::cases() as $license) {
            $deedUrl = $license->deedUrl();
            if ($deedUrl !== null) {
                $deeds[$license->value] = $deedUrl . 'deed.zh-hans';
            }
        }

        // 后面的问题由脚本根据前面的回答显示
        $questions = '';
        foreach ([
            'attribution' => [_t('他人使用你的作品时，是否要为你署名？'), [
                'yes' => _t('要求署名（BY）'),
                'no'  => _t('不要求，放弃我对作品的权利（CC0）'),
            ]],
            'commercial' => [_t('是否允许他人商用你的作品？'), [
                'yes' => _t('允许'),
                'no'  => _t('不允许（NC，非商业性使用）'),
            ]],
            'adaptations' => [_t('是否允许他人改编你的作品并再次分享，例如翻译、改写、混编？'), [
                'yes' => _t('允许'),
                'no'  => _t('不允许，只能原样分享（ND，禁止演绎）'),
            ]],
            'shareAlike' => [_t('改编后的作品是否要用相同的协议分享？'), [
                'yes' => _t('要求（SA，相同方式共享）'),
                'no'  => _t('不要求，改编者可以自选协议'),
            ]],
        ] as $name => [$prompt, $answers]) {
            $questions .= self::question($name, $prompt, $answers, $questions === '');
        }

        return '<details class="copyright-chooser" data-select="' . Html::escape($select) . '" data-deeds="' . Html::escape((string) json_encode($deeds)) . '">'
            . '<summary>' . _t('不知道如何选择？') . '</summary>'
            . '<div class="copyright-chooser-body">'
            . '<ol>' . $questions . '</ol>'
            . '<div class="copyright-chooser-result" hidden>'
            . '<p>' . _t('推荐协议：') . '<strong data-recommendation></strong> · '
            . '<a data-deed target="_blank" rel="noopener">' . _t('查看协议说明') . '</a></p>'
            . '<p class="description" data-cc0-note hidden>' . _t('CC0 不是许可协议，而是在法律允许的范围内放弃你对作品的著作权及相关权利，让作品进入公有领域：任何人都可以不署名、不经许可地以任何方式使用，包括商用和改编。') . '</p>'
            . '<p class="description">' . _t('只有版权所有人才有权在发布时附带协议。（转载内容若受到原作者协议的限制，要求以相同协议分享，可在自定义字段中单独填写原作者的协议。）') . '</p>'
            . '<p class="description">' . _t('一经发布便不可撤回，已按协议获得作品的人可以一直按协议使用，即使你事后更换协议或删除作品。') . '</p>'
            . '<p><button type="button" class="btn btn-s" data-apply>' . _t('选用该协议') . '</button> '
            . '<span class="description" data-applied hidden>' . Html::escape($appliedNote) . '</span></p>'
            . '</div>'
            . '</div>'
            . '</details>';
    }

    /**
     * @param array<string, string> $answers
     */
    private static function question(string $name, string $prompt, array $answers, bool $isShown): string
    {
        $choices = '';
        foreach ($answers as $value => $label) {
            $choices .= "<label><input type=\"radio\" name=\"copyright-chooser-{$name}\" value=\"{$value}\" /> {$label}</label>";
        }

        $hidden = $isShown ? '' : ' hidden';

        return "<li data-question=\"{$name}\"{$hidden}><p>{$prompt}</p>{$choices}</li>";
    }
}
