<?php

declare(strict_types=1);

require_once __DIR__ . '/DemoMode.php';

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function format_number_clean(int|float|string|null $value): string
{
    if ($value === null || $value === '') {
        return '0';
    }

    if (!is_numeric($value)) {
        return (string) $value;
    }

    $formatted = number_format((float) $value, 2, '.', ',');
    $formatted = rtrim($formatted, '0');
    $formatted = rtrim($formatted, '.');

    return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
}

function post_string(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function post_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : $value;
}

function post_decimal(string $key, string $default = '0.00'): string
{
    $value = post_string($key, $default);
    return preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $value) === 1 ? $value : $default;
}

function safe_error_message(): string
{
    return '處理失敗，請檢查輸入內容或稍後再試。';
}

function render_demo_banner(): void
{
    if (!DemoMode::isEnabled()) {
        return;
    }
    ?>
    <aside class="demo-mode-banner" role="status" aria-label="展示模式狀態">
        <div class="demo-mode-banner-inner">
            <strong><span class="demo-mode-dot" aria-hidden="true"></span>本機互動展示</strong>
            <span>合成資料 · 獨立 Demo DB · 外部 AI 與未驗證 API 已停用</span>
            <a href="/dashboard.php">展示首頁</a>
        </div>
    </aside>
    <script>document.documentElement.classList.add('demo-mode-active');</script>
    <?php
}

function render_ledger_edit_modal(
    string $refreshTargets,
    string $pageStatusId,
    string $resultLabel
): void {
    ?>
    <p id="<?= h($pageStatusId) ?>" class="ajax-status" role="status" aria-live="polite" hidden></p>
    <div
        class="ledger-modal"
        id="ledger-edit-modal"
        data-refresh-targets="<?= h($refreshTargets) ?>"
        data-page-status-id="<?= h($pageStatusId) ?>"
        data-result-label="<?= h($resultLabel) ?>"
        hidden
        aria-hidden="true"
    >
        <div class="ledger-modal-backdrop" data-modal-close="1"></div>
        <section class="ledger-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ledger-edit-title" aria-describedby="ledger-edit-status">
            <div class="ledger-modal-header">
                <h2 id="ledger-edit-title">編輯項目</h2>
                <button type="button" class="secondary" id="ledger-edit-close">關閉</button>
            </div>
            <p id="ledger-edit-status" class="ledger-modal-status" role="status" aria-live="polite"></p>
            <form id="ledger-edit-form" class="grid-form" novalidate>
                <div id="ledger-edit-fields" class="modal-form-fields"></div>
                <div class="modal-actions">
                    <button type="submit" id="ledger-edit-submit">儲存</button>
                    <button type="button" class="secondary" id="ledger-edit-cancel">取消</button>
                    <button type="button" class="danger modal-delete-button" id="ledger-edit-delete" hidden>刪除此項目</button>
                </div>
            </form>
        </section>
    </div>
    <?php
}

function render_mobile_nav(string $active = ''): void
{
    $items = [
        'dashboard' => ['首頁', '/dashboard.php'],
        'finance' => ['收支', '/finance.php'],
        'work' => ['工作', '/work.php'],
        'back' => ['返回', '#'],
    ];
    render_demo_banner();
    ?>
    <nav class="mobile-bottom-nav" aria-label="手機導覽">
        <?php foreach ($items as $key => [$label, $href]): ?>
            <?php if ($key === 'back'): ?>
                <a href="<?= h($href) ?>" data-back-nav="1"><?= h($label) ?></a>
            <?php else: ?>
                <a href="<?= h($href) ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= h($label) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table').forEach(function (table) {
            var headers = Array.from(table.querySelectorAll('thead th')).map(function (th) {
                return th.textContent.trim();
            });
            table.querySelectorAll('tbody tr').forEach(function (row) {
                row.classList.add('mobile-card');
                Array.from(row.children).forEach(function (cell, index) {
                    cell.classList.add('mobile-row');
                    if (headers[index] && !cell.hasAttribute('data-label')) {
                        cell.setAttribute('data-label', headers[index]);
                    }
                    if (!cell.querySelector('input, select, textarea, button, a, form') && cell.textContent.trim() === '') {
                        cell.textContent = '-';
                    }
                });
            });
        });

        document.querySelectorAll('[data-back-nav="1"]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                if (window.history.length > 1) {
                    window.history.back();
                    return;
                }
                window.location.href = '/dashboard.php';
            });
        });

        document.querySelectorAll('form').forEach(function (form) {
            var action = form.querySelector('input[name="action"][value="delete"]');
            if (!action) {
                return;
            }
            form.addEventListener('submit', function (event) {
                if (!window.confirm('確定要刪除這筆資料？')) {
                    event.preventDefault();
                }
            });
        });
    });
    </script>
    <?php
}
