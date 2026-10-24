/**
 * 许可协议的辅助选择
 *
 * 问题和文案由设置页或编辑页输出，这里按 Creative Commons 协议选择器的流程决定显示哪些问题、推荐哪个协议，
 * 并在用户选用后填入下拉框。保存的始终是下拉框的值。
 */
const chooser = document.querySelector('.copyright-chooser');
const select = chooser && document.querySelector(`select[name="${CSS.escape(chooser.dataset.select)}"]`);

if (chooser && select) {
    const deeds = JSON.parse(chooser.dataset.deeds);
    const result = chooser.querySelector('.copyright-chooser-result');
    const applyButton = chooser.querySelector('[data-apply]');
    const appliedNote = chooser.querySelector('[data-applied]');

    const question = (name) => chooser.querySelector(`[data-question="${name}"]`);
    const answer = (name) => question(name).querySelector('input:checked')?.value;
    const check = (name, value) => {
        question(name).querySelector(`input[value="${value}"]`).checked = true;
    };

    /** 按问题的先后顺序推出协议，还缺回答时返回 null */
    const recommend = () => {
        const attribution = answer('attribution');
        if (attribution === 'no') {
            return 'cc0';
        }

        const commercial = answer('commercial');
        const adaptations = answer('adaptations');
        if (attribution !== 'yes' || !commercial || !adaptations) {
            return null;
        }

        const base = commercial === 'yes' ? 'by' : 'by-nc';
        if (adaptations === 'no') {
            return `${base}-nd`;
        }

        const shareAlike = answer('shareAlike');
        if (!shareAlike) {
            return null;
        }

        return shareAlike === 'yes' ? `${base}-sa` : base;
    };

    const update = () => {
        const requiresAttribution = answer('attribution') === 'yes';
        question('commercial').hidden = !requiresAttribution;
        question('adaptations').hidden = !requiresAttribution;
        question('shareAlike').hidden = !requiresAttribution || answer('adaptations') !== 'yes';

        const license = recommend();
        result.hidden = license === null;
        if (license !== null) {
            chooser.querySelector('[data-recommendation]').textContent =
                select.querySelector(`option[value="${license}"]`).textContent.trim();
            chooser.querySelector('[data-deed]').href = deeds[license];
        }

        chooser.querySelector('[data-cc0-note]').hidden = license !== 'cc0';
        appliedNote.hidden = true;
    };

    /** 下拉框当前是 CC 协议时，预先填好对应的回答 */
    const answerFromSelect = () => {
        chooser.querySelectorAll('input').forEach((input) => {
            input.checked = false;
        });

        const license = select.value;
        if (license === 'cc0') {
            check('attribution', 'no');
        } else if (/^by(-nc)?(-sa|-nd)?$/.test(license)) {
            check('attribution', 'yes');
            check('commercial', license.includes('-nc') ? 'no' : 'yes');
            check('adaptations', license.includes('-nd') ? 'no' : 'yes');
            if (!license.includes('-nd')) {
                check('shareAlike', license.includes('-sa') ? 'yes' : 'no');
            }
        }

        update();
    };

    chooser.addEventListener('change', update);
    select.addEventListener('change', answerFromSelect);
    applyButton.addEventListener('click', () => {
        select.value = recommend();
        appliedNote.hidden = false;
    });

    answerFromSelect();
}
