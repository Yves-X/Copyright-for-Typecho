<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Typecho\Db;
use Typecho\Widget;
use Widget\ActionInterface;
use Widget\Notice;
use Widget\Security;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 后台操作，地址为 /action/copyright
 */
final class Action extends Widget implements ActionInterface
{
    public const NAME = 'copyright';

    public function action(): void
    {
        Security::alloc()->protect();

        if ($this->request->is('do=preview')) {
            // 撰写文章的用户也要能预览
            User::alloc()->pass('contributor');
            $this->response->throwJson(['html' => $this->preview()]);
        }

        User::alloc()->pass('administrator');

        if ($this->request->is('do=migrate-fields')) {
            $this->migrateFields();
        } elseif ($this->request->is('do=update-sealed') || $this->request->is('do=keep-sealed')) {
            $this->resolveOutdated($this->request->is('do=update-sealed'));
        } elseif ($this->request->is('do=seal-contents')) {
            $this->sealContents();
        }

        $this->response->goBack();
    }

    /**
     * 设置页预览尚未保存的设置，编辑页预览尚未保存的字段
     */
    private function preview(): string
    {
        if ($this->request->get('context') === 'settings') {
            $saved = [];
            foreach (Settings::ARRAY_KEYS as $key) {
                $saved[$key] = $this->request->getArray($key);
            }
            foreach (Settings::TEXT_KEYS as $key) {
                $saved[$key] = (string) $this->request->get($key);
            }

            return Preview::forSettings(Settings::fromSaved($saved));
        }

        $type = $this->request->get('type') === 'page' ? 'page' : 'post';

        // 投稿者只能预览自己的内容，否则按新内容预览
        $cid = (int) $this->request->get('cid');
        if ($cid > 0 && !$this->canEdit($cid)) {
            $cid = 0;
        }

        return Preview::forEditor(
            $cid,
            $type,
            trim((string) $this->request->get('title')),
            $this->request->getArray('fields')
        );
    }

    private function canEdit(int $cid): bool
    {
        $user = User::alloc();
        if ($user->pass('editor', true)) {
            return true;
        }

        $db = Db::get();
        $row = $db->fetchRow($db->select('authorId')->from('table.contents')->where('cid = ?', $cid));

        return $row && (int) $row['authorId'] === (int) $user->uid;
    }

    private function sealContents(): void
    {
        $cids = array_values(array_map('intval', $this->request->getArray('cids')));
        if ($cids === []) {
            Notice::alloc()->set(_t('没有选择要处理的项'), 'notice');
            return;
        }

        Notice::alloc()->set(_t('已为 %d 篇内容写入版权信息', Seal::seal($cids)), 'success');
    }

    private function resolveOutdated(bool $isUpdate): void
    {
        $selected = [];
        foreach ($this->request->getArray('sealed') as $name => $cids) {
            if (in_array($name, Seal::FIELDS, true) && is_array($cids)) {
                $selected[$name] = array_values(array_map('intval', $cids));
            }
        }

        if ($selected === []) {
            Notice::alloc()->set(_t('没有选择要处理的项'), 'notice');
            return;
        }

        $count = Seal::resolveOutdated($selected, $isUpdate);
        Notice::alloc()->set($isUpdate ? _t('已更新 %d 篇内容', $count) : _t('已保持 %d 篇内容的原样', $count), 'success');
    }

    private function migrateFields(): void
    {
        $legacyNames = array_values(array_filter($this->request->getArray('legacy'), 'is_string'));
        if ($legacyNames === []) {
            Notice::alloc()->set(_t('没有选择要迁移的字段'), 'notice');
            return;
        }

        $result = Upgrade::migrateFields($legacyNames);
        $message = _t('已迁移 %d 个字段', $result['migrated']);
        if ($result['skipped'] > 0) {
            $message .= _t('，另有 %d 个因新字段已有不同的值而保留原样', $result['skipped']);
        }

        Notice::alloc()->set($message, 'success');
    }
}
