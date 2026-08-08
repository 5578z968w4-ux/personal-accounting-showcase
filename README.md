# 個人記帳與薪資管理系統

[English](README.en.md)

這是一套以 PHP 8.2、MariaDB 11、Apache 與 Docker Compose 建置的行動優先個人記帳系統，整合支出、收入、薪資試算、加班、請假、統計分析、PWA 快速輸入，以及可選用的 Gemini 輔助解析流程。

> 這是經過隱私審查的公開展示版本。Repository 僅包含重新建立的公開 Git 歷史，不包含私有原始歷史、真實環境檔、資料庫備份、匯入檔、執行紀錄或正式憑證。完整發布狀態與操作邊界請參閱 `PUBLIC_RELEASE_SCOPE.md`。

## 線上互動展示

不需要下載或安裝，可直接在瀏覽器操作合成資料的靜態展示：

[立即體驗線上互動展示](https://5578z968w4-ux.github.io/personal-accounting-showcase/showcase/)

這是純前端靜態展示；新增、編輯、刪除和設定互動只存在於目前的瀏覽器分頁，不會連線 PHP、MariaDB、NAS 或外部 API，也不會儲存資料。

## 這個展示可以真的操作嗎？

可以。這個 repository 不只是展示圖片，也包含可執行的完整應用程式、Docker 環境、資料庫 migration、合成 Demo 資料與自動化測試。

GitHub 首頁本身只會呈現 README、原始碼與截圖，不會直接執行 PHP 或 MariaDB。將 repository clone 到本機並依下方指令啟動後，即可登入真正可操作的隔離 Demo。

## 功能亮點

| 功能 | 說明 |
| --- | --- |
| 儀表板 | 顯示月份收支、薪資試算、結餘與近期紀錄 |
| 支出與收入 | 支援新增、編輯、軟刪除、篩選與分類分析 |
| 付款方式與帳戶 | 由後台設定，不在程式中寫死 |
| 帳單月份 | 依付款方式的結算週期自動計算 |
| 薪資管理 | 管理每月工作天、薪資參數與薪資明細 |
| 加班與請假 | 管理同日唯一紀錄、時數、天數與假別 |
| AI 輔助輸入 | 可選用 Gemini 解析，具伺服器端驗證與 Trace 紀錄 |
| 行動版與 PWA | 提供手機友善後台與快速輸入介面 |
| 品質驗證 | 包含 PHP lint、focused tests、公開邊界與完整 Git 歷史掃描 |

## 合成 Demo 截圖

桌面版儀表板：

![合成資料桌面版儀表板](docs/screenshots/dashboard-demo.png)

手機版儀表板：

![合成資料手機版儀表板](docs/screenshots/dashboard-mobile-demo.png)

截圖中的付款方式、帳戶、記帳對象、交易與工作紀錄全部都是固定合成資料，不代表任何真實人物或真實帳務模式。

## 啟動可操作的本機 Demo

Demo 使用獨立 Compose project、獨立資料庫名稱與公開範例環境設定，不會使用一般環境的資料庫。

```bash
git clone https://github.com/5578z968w4-ux/personal-accounting-showcase.git
cd personal-accounting-showcase

docker compose \
  -p personal_accounting_demo_public \
  --env-file .env.demo.example \
  up -d --build

docker compose \
  -p personal_accounting_demo_public \
  --env-file .env.demo.example \
  exec app php /var/www/html/scripts/migrate.php

docker compose \
  -p personal_accounting_demo_public \
  --env-file .env.demo.example \
  exec app php /var/www/html/scripts/demo_reset.php
```

開啟 `http://127.0.0.1:18085/`，使用以下公開 Demo 帳密：

- 帳號：`demo`
- 密碼：`demo-local-only`

`.env.demo.example` 內的帳密只供隔離本機 Demo 使用，不可重複用於任何對外服務。

## Demo 安全邊界

- Demo 只使用 `personal_accounting_demo` 與固定合成資料。
- `demo_reset.php` 必須同時確認 `APP_ENV=demo`、`DEMO_MODE=1`，且設定與實際連線的資料庫名稱都等於 `personal_accounting_demo`，否則拒絕執行。
- Demo 模式不呼叫 Gemini，並停用未登入 Quick Entry、Shortcut API 與資料庫診斷端點。
- Web port 預設只綁定 `127.0.0.1`。
- MariaDB 沒有對外 host port，只能由 Compose network 內的 app container 連線。
- Apache 與 PHP 執行紀錄使用專案專屬 Docker named volumes，不寫回原始碼目錄。
- `.env` 只應放在專案根目錄，且已由 Git 忽略；Apache 只提供 `app/public`。
- Demo 截圖、fixture 與展示環境只能使用合成資料。

`quick_entry.php` 與 `quick_entry_api.php` 原本是為可信任的私人網路設計，不是已驗證的公開 webhook。若要把應用程式部署到網際網路，必須另外加入授權層、HTTPS、rate limiting、CSRF 審查與濫用防護。

`db-test.php` 會寫入診斷紀錄；maintenance 與 production import scripts 也可能修改資料。未完成備份、目標確認與明確審查前，不應對真實資料庫執行。

## 一般本機開發環境

如果不是啟動公開 Demo，請先複製一般環境範例，並更換所有密碼 placeholder：

```bash
cp .env.example .env
chmod 600 .env
docker compose up -d --build
docker compose exec app php /var/www/html/scripts/migrate.php
```

除非確定要永久刪除本機資料庫 volume，否則不要執行 `docker compose down -v`。

## 本機檢查

PHP 語法檢查：

```bash
find app -name '*.php' -print0 | xargs -0 -n1 php -l
```

不需要正式資料庫的 focused tests：

```bash
for test_file in app/tests/*Test.php; do php "$test_file"; done
```

檢查公開檔案 allowlist、敏感資訊、內部路徑與禁止出現的真實世界 Demo 標籤：

```bash
php app/scripts/public_release_check.php
```

建立至少一筆本機 commit 後，掃描所有本機 Git blobs、unreachable objects 與歷史路徑：

```bash
php app/scripts/public_git_history_check.php
```

GitHub Actions 使用唯讀 repository 權限、不使用 secrets、會 checkout 完整公開歷史，並執行 Compose 設定檢查、公開邊界掃描、完整 Git 歷史掃描、PHP lint 與 focused tests。

## 公開版本狀態

這個公開展示版本刻意排除私有 repository 歷史、基礎設施路徑、內部操作報告、真實資料與正式憑證。公開 Git 歷史從審核完成的 allowlist 重新建立。

- 精確公開檔案清單：`PUBLIC_ALLOWLIST.txt`
- 發布狀態與操作邊界：`PUBLIC_RELEASE_SCOPE.md`
- 圖示來源與製作依據：`ASSET_PROVENANCE.md`
- 安全通報方式：`SECURITY.md`
- 完整英文說明：`README.en.md`

## 技術架構

- PHP 8.2 + Apache
- MariaDB 11
- Docker Compose
- 原生 JavaScript 與 CSS
- SQLite-focused tests
- GitHub Actions

## 授權

本專案採用 MIT License，詳見 `LICENSE`。
