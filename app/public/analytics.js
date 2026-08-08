(function () {
    'use strict';

    var modal = document.getElementById('ledger-edit-modal');
    var form = document.getElementById('ledger-edit-form');
    var fields = document.getElementById('ledger-edit-fields');
    var modalTitle = document.getElementById('ledger-edit-title');
    var modalStatus = document.getElementById('ledger-edit-status');
    var pageStatus = document.getElementById(modal ? modal.getAttribute('data-page-status-id') : '');
    var refreshTargets = modal ? (modal.getAttribute('data-refresh-targets') || '').split(',').map(function (targetId) {
        return targetId.trim();
    }).filter(Boolean) : [];
    var resultLabel = modal ? (modal.getAttribute('data-result-label') || '列表') : '列表';
    var submitButton = document.getElementById('ledger-edit-submit');
    var deleteButton = document.getElementById('ledger-edit-delete');
    var closeButton = document.getElementById('ledger-edit-close');
    var cancelButton = document.getElementById('ledger-edit-cancel');
    var activeType = '';
    var activeId = '';
    var activeLabel = '';
    var lastTrigger = null;
    var modalRequestId = 0;

    if (!modal || !form || !fields || !modalTitle || !modalStatus || !submitButton || !deleteButton) {
        return;
    }

    function stringValue(value) {
        return value === null || value === undefined ? '' : String(value);
    }

    function setStatus(element, message, isError) {
        if (!element) {
            return;
        }
        element.textContent = message || '';
        element.hidden = !message;
        element.classList.toggle('error', Boolean(isError));
    }

    function focusWithoutScroll(element) {
        if (!element) {
            return;
        }
        try {
            element.focus({preventScroll: true});
        } catch (error) {
            element.focus();
        }
    }

    function createHidden(name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = stringValue(value);
        return input;
    }

    function addField(labelText, control, wide) {
        var label = document.createElement('label');
        if (wide) {
            label.className = 'wide';
        }
        label.appendChild(document.createTextNode(labelText));
        label.appendChild(control);
        fields.appendChild(label);
    }

    function addInput(labelText, name, type, value, required, wide) {
        var input = document.createElement('input');
        input.type = type;
        input.name = name;
        input.value = stringValue(value);
        if (required) {
            input.required = true;
        }
        if (type === 'number') {
            input.min = '0';
            input.step = '0.01';
            input.inputMode = 'decimal';
        }
        addField(labelText, input, wide);
    }

    function addTextarea(labelText, name, value) {
        var textarea = document.createElement('textarea');
        textarea.name = name;
        textarea.value = stringValue(value);
        addField(labelText, textarea, true);
    }

    function addSelect(labelText, name, options, selectedValue, wide) {
        var select = document.createElement('select');
        select.name = name;
        options.forEach(function (option) {
            var optionElement = document.createElement('option');
            optionElement.value = stringValue(option.value);
            optionElement.textContent = stringValue(option.label);
            optionElement.selected = optionElement.value === stringValue(selectedValue);
            select.appendChild(optionElement);
        });
        addField(labelText, select, wide);
    }

    function setBusy(isBusy, operation) {
        submitButton.disabled = isBusy;
        deleteButton.disabled = isBusy;
        form.setAttribute('aria-busy', isBusy ? 'true' : 'false');
        submitButton.textContent = isBusy && operation === 'save' ? '儲存中…' : '儲存';
        deleteButton.textContent = isBusy && operation === 'delete' ? '刪除中…' : '刪除此項目';
    }

    function renderExpenseForm(payload) {
        var record = payload.record || {};
        var paymentMethods = (payload.payment_methods || []).map(function (method) {
            return {
                value: method.id,
                label: stringValue(method.name) + (Number(method.is_active) === 0 ? '（停用）' : '')
            };
        });
        var entryOwners = Object.keys(payload.entry_owners || {}).map(function (value) {
            return {value: value, label: payload.entry_owners[value]};
        });

        addInput('日期', 'record_date', 'date', record.record_date, true, false);
        addInput('項目', 'item', 'text', record.item, true, false);
        addInput('金額', 'amount', 'number', record.amount, true, false);
        addSelect('付款方式', 'payment_method_id', paymentMethods, record.payment_method_id, false);
        addInput('分類', 'category', 'text', record.category, false, false);
        form.appendChild(createHidden('user_name', record.user_name));
        addSelect('記帳對象', 'entry_owner', entryOwners, record.entry_owner || 'profile_a', false);
        addTextarea('備註', 'raw_input', record.raw_input);
    }

    function renderIncomeForm(payload) {
        var record = payload.record || {};
        var accounts = [{value: '', label: '未指定'}].concat((payload.accounts || []).map(function (account) {
            return {
                value: account.id,
                label: stringValue(account.name) + (Number(account.is_active) === 0 ? '（停用）' : '')
            };
        }));

        addInput('日期', 'record_date', 'date', record.record_date, true, false);
        addInput('來源', 'source_name', 'text', record.source_name, true, false);
        addInput('金額', 'amount', 'number', record.amount, true, false);
        addSelect('帳戶', 'account_id', accounts, record.account_id, false);
        addInput('分類', 'category', 'text', record.category, false, false);
        addInput('記帳人', 'user_name', 'text', record.user_name, false, false);
        addTextarea('備註', 'raw_input', record.raw_input);
    }

    function renderForm(payload) {
        var record = payload.record || {};
        fields.textContent = '';
        form.insertBefore(createHidden('action', 'save'), fields);
        form.insertBefore(createHidden('id', record.id), fields);
        form.insertBefore(createHidden('ajax', '1'), fields);
        if (payload.type === 'expense') {
            modalTitle.textContent = '編輯支出';
            activeLabel = stringValue(record.item) || '未命名支出';
            renderExpenseForm(payload);
        } else {
            modalTitle.textContent = '編輯收入';
            activeLabel = stringValue(record.source_name) || '未命名收入';
            renderIncomeForm(payload);
        }
        deleteButton.hidden = false;
    }

    function requestJson(url, options) {
        return fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        }, options || {})).then(function (response) {
            return response.json().catch(function () {
                return {ok: false, message: '伺服器回應格式不正確。'};
            }).then(function (payload) {
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || '處理失敗，請稍後再試。');
                }
                return payload;
            });
        });
    }

    function openModal(trigger) {
        var requestId = ++modalRequestId;
        activeType = trigger.getAttribute('data-ledger-type') || '';
        activeId = trigger.getAttribute('data-ledger-id') || '';
        lastTrigger = trigger;
        activeLabel = '';
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        fields.textContent = '載入中…';
        deleteButton.hidden = true;
        setStatus(modalStatus, '正在載入資料…', false);
        setBusy(true, 'load');
        focusWithoutScroll(closeButton);

        var endpoint = activeType === 'income' ? '/incomes.php' : '/expenses.php';
        requestJson(endpoint + '?ajax=edit&id=' + encodeURIComponent(activeId))
            .then(function (payload) {
                if (requestId !== modalRequestId) {
                    return;
                }
                renderForm(payload);
                setStatus(modalStatus, '', false);
                setBusy(false, 'load');
                var firstInput = fields.querySelector('input, select, textarea');
                focusWithoutScroll(firstInput);
            })
            .catch(function (error) {
                if (requestId !== modalRequestId) {
                    return;
                }
                fields.textContent = '';
                setStatus(modalStatus, error.message, true);
                setBusy(false, 'load');
            });
    }

    function closeModal() {
        modalRequestId += 1;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        setStatus(modalStatus, '', false);
        deleteButton.hidden = true;
        fields.textContent = '';
        form.querySelectorAll('input[type="hidden"]').forEach(function (input) {
            input.remove();
        });
        if (lastTrigger && document.contains(lastTrigger)) {
            focusWithoutScroll(lastTrigger);
        }
        lastTrigger = null;
        activeLabel = '';
    }

    function refreshResults() {
        return fetch(window.location.href, {
            credentials: 'same-origin',
            headers: {'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest'},
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error(resultLabel + '重新整理失敗。');
            }
            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            if (refreshTargets.length === 0) {
                throw new Error('找不到要重新整理的區塊設定。');
            }
            var replacements = refreshTargets.map(function (targetId) {
                var freshResults = parsed.getElementById(targetId);
                var currentResults = document.getElementById(targetId);
                if (!freshResults || !currentResults) {
                    throw new Error('找不到' + resultLabel + '更新區塊。');
                }
                return {current: currentResults, fresh: freshResults};
            });
            replacements.forEach(function (replacement) {
                replacement.current.replaceWith(replacement.fresh);
            });
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var trigger = target ? target.closest('[data-ledger-edit="1"]') : null;
        if (trigger) {
            event.preventDefault();
            openModal(trigger);
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!activeId || submitButton.disabled) {
            return;
        }
        setBusy(true, 'save');
        setStatus(modalStatus, '正在儲存…', false);
        var endpoint = activeType === 'income' ? '/incomes.php' : '/expenses.php';
        requestJson(endpoint, {method: 'POST', body: new FormData(form)})
            .then(function (payload) {
                closeModal();
                setStatus(pageStatus, payload.message || '已儲存。正在更新列表…', false);
                return refreshResults();
            })
            .then(function () {
                setStatus(pageStatus, '已儲存，' + resultLabel + '已更新。', false);
            })
            .catch(function (error) {
                setBusy(false, 'save');
                setStatus(modalStatus, error.message, true);
                setStatus(pageStatus, error.message, true);
            });
    });

    deleteButton.addEventListener('click', function () {
        if (!activeId || deleteButton.disabled) {
            return;
        }

        var typeLabel = activeType === 'income' ? '收入' : '支出';
        if (!window.confirm('確定要刪除' + typeLabel + '「' + activeLabel + '」嗎？刪除後將不會出現在收支明細。')) {
            return;
        }

        var deleteData = new FormData();
        deleteData.append('action', 'delete');
        deleteData.append('id', activeId);
        deleteData.append('ajax', '1');
        setBusy(true, 'delete');
        setStatus(modalStatus, '正在刪除…', false);

        var endpoint = activeType === 'income' ? '/incomes.php' : '/expenses.php';
        requestJson(endpoint, {method: 'POST', body: deleteData})
            .then(function (payload) {
                closeModal();
                setStatus(pageStatus, payload.message || '已刪除。正在更新列表…', false);
                return refreshResults();
            })
            .then(function () {
                setStatus(pageStatus, '已刪除，' + resultLabel + '已更新。', false);
            })
            .catch(function (error) {
                setBusy(false, 'delete');
                setStatus(modalStatus, error.message, true);
                setStatus(pageStatus, error.message, true);
            });
    });

    [closeButton, cancelButton].forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', function (event) {
        if (event.target.getAttribute('data-modal-close') === '1') {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
            return;
        }

        if (event.key === 'Tab' && !modal.hidden) {
            var focusable = Array.prototype.slice.call(modal.querySelectorAll(
                'button:not([disabled]):not([hidden]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]'
            )).filter(function (element) {
                return element.offsetParent !== null;
            });
            if (focusable.length === 0) {
                event.preventDefault();
                return;
            }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                focusWithoutScroll(last);
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                focusWithoutScroll(first);
            }
        }
    });
}());
