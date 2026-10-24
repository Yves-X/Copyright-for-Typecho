/**
 * 设置页和编辑页的交互：实时预览、作者和声明的填写方式、原创与转载的切换、列表的全选
 *
 * 没有脚本时表单照常可用，只是没有预览，原文链接输入框总是显示。
 */

/** 预览由服务端按与前台相同的逻辑渲染，表单改动后稍等片刻再请求 */
const preview = document.querySelector('.copyright-preview');
if (preview) {
    const form = preview.closest('form');
    const body = preview.querySelector('.copyright-preview-body');
    let timer;
    let latest = 0;

    const refresh = async () => {
        const data = new FormData(form);
        data.delete('do');
        data.set('context', preview.dataset.context);
        data.set('type', preview.dataset.type);

        const request = ++latest;
        try {
            const response = await fetch(preview.dataset.url, { method: 'POST', body: data, credentials: 'same-origin' });
            const result = await response.json();
            if (request === latest) {
                body.innerHTML = result.html;
            }
        } catch (e) {
            // 预览失败不影响保存
        }
    };

    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(refresh, 300);
    };

    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
    refresh();
}

/** 作者和声明：套用全局设置、自定义、不显示，分别把字段设为留空、所填的值和 0 */
const modes = [...document.querySelectorAll('.copyright-mode')].map((group) => {
    const input = document.querySelector(`input[name="${group.dataset.for}"]`);
    const initial = input.value.trim();
    let custom = initial !== '' && initial !== '0' ? input.value : '';

    const option = (mode) => group.querySelector(`input[value="${mode}"]`);
    const current = () => group.querySelector('input:checked').value;
    const set = (mode) => {
        option(mode).checked = true;
        input.hidden = mode !== 'custom';
        input.required = mode === 'custom';
        input.value = { inherit: '', off: '0', custom }[mode];
    };

    input.before(group);
    group.hidden = false;
    set(initial === '' ? 'inherit' : initial === '0' ? 'off' : 'custom');

    // 来回切换时记住填过的内容
    input.addEventListener('input', () => {
        custom = input.value;
    });
    group.addEventListener('change', () => {
        set(current());
        if (current() === 'custom') {
            input.focus();
        }
    });

    return { option, current, set };
});

/** 原创还是转载只取决于有没有原文链接：选择原创时清空原文链接，选择转载时必须填写 */
const origin = document.querySelector('.copyright-origin');
const sourceUrl = document.querySelector('input[name="fields[copyrightSourceUrl]"]');
if (origin && sourceUrl) {
    const license = document.querySelector('select[name="fields[copyrightLicense]"]');

    // 填 0 表示原创且不显示本文链接，保留原值
    const value = sourceUrl.value.trim();
    origin.querySelector(`input[value="${value !== '' && value !== '0' ? 'repost' : 'original'}"]`).checked = true;
    sourceUrl.before(origin);

    // 转载不套用全局设置，没有这一项；原本套用的改为不显示，与转载时的实际效果相同
    const update = () => {
        const isRepost = origin.querySelector('input:checked').value === 'repost';
        sourceUrl.hidden = !isRepost;
        sourceUrl.required = isRepost;
        modes.forEach((mode) => {
            mode.option('inherit').parentElement.hidden = isRepost;
            if (isRepost && mode.current() === 'inherit') {
                mode.set('off');
            }
        });
        if (license) {
            const inherit = license.querySelector('option[value=""]');
            inherit.hidden = isRepost;
            if (isRepost && license.value === '') {
                license.value = '0';
                license.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    };

    origin.addEventListener('change', () => {
        if (origin.querySelector('input:checked').value === 'original' && sourceUrl.value.trim() !== '0') {
            sourceUrl.value = '';
        }
        update();
        if (!sourceUrl.hidden) {
            sourceUrl.focus();
        }
    });
    update();
}

/** 全选：勾选或取消范围内的所有选框 */
document.addEventListener('change', (event) => {
    const toggle = event.target.closest('[data-toggle-all]');
    if (toggle) {
        toggle.closest('[data-toggle-scope]').querySelectorAll('input[type="checkbox"]').forEach((input) => {
            input.checked = toggle.checked;
        });
    }
});
