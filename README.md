# WebPatch

日本語 | [English](#english)

WebPatch は、構築したウェブサイトに対するフィードバック、修正依頼、反映確認のコミュニケーションを円滑にするための、ウェブ制作向けコミュニケーション支援ツールです。

HTML サイトをアップロードまたは URL から取り込み、実際のブラウザ表示を見ながらページ上の要素に直接コメントを残せます。制作担当者、確認担当者、クライアントの間で起きやすい「どのページのどの部分の話か分からない」「修正依頼が散らばる」「対応済みか確認しづらい」といった問題を減らすことを目的にしています。

## 解決したい課題

ウェブサイト制作では、確認や修正依頼がメール、チャット、スプレッドシート、スクリーンショットなどに分散しがちです。その結果、次のような負担が発生します。

- 指摘箇所を URL、画面幅、スクリーンショット、文章で説明する必要がある
- 制作者が「どの要素のことか」を探す時間が増える
- コメントへの返信、対応状況、完了確認が別々の場所に分散する
- 公開前サイトやローカルで書き出した HTML の確認共有が面倒になる
- 修正後に、過去のコメントが本当に反映されたか確認しづらい

WebPatch は、HTML プレビュー、要素単位のコメント、ステータス管理、公開リンク、AI による反映確認を 1 つの画面にまとめ、制作コミュニケーションの摩擦を下げます。

## 主な機能

### サイト登録

- HTML とアセットを含む ZIP ファイルをアップロードして、確認用サイトとして登録
- CSV の URL リストから同一ドメインのページを取得して登録
- Basic 認証付きサイトの取り込みと再取得に対応
- 登録済み URL サイトの HTML を再取得して更新
- ZIP 登録サイトでは、ページ単体の上書きやサイト全体の差し替えが可能

### ブラウザ上でのレビュー

- iframe 内に実際の HTML 表示を再現
- デスクトップ、タブレット、モバイルのプレビュー幅を切り替え
- ページ一覧から確認対象ページを移動
- HTML 内の相対リンク、画像、CSS、JS などを WebPatch 経由のパスに書き換えて表示
- プレビューを別タブで開いて全画面確認

### コメントとやり取り

- ページ上の要素に対して直接コメントを追加
- コメントへの返信スレッド
- コメント編集、削除、解決済み切り替え
- 「確認待ち」状態の管理
- コメントへの画像添付
- ページごとの未対応コメント表示
- 共有メンバーとゲストの両方に対応

### 共有と公開レビュー

- 登録済みユーザーへのプロジェクト共有
- コメントのみ、編集可の権限切り替え
- 未登録ユーザーへの招待リンク送信
- 公開リンクを発行して、ログインなしのゲストレビューを受け付け
- 公開リンクの無効化、再発行

### コメントシートと外部 API

- コメントをシート形式で一覧表示
- ステータス、希望完了日時、AI 確認結果を一覧で確認
- API トークンを発行して外部ツールからコメント一覧取得、更新
- `todo`、`doing`、`done` のステータス管理

### AI による反映確認

- OpenAI、Gemini、Grok の API キーをユーザーごとに保存
- コメントの内容と現在の HTML を照合し、修正依頼が反映済みかを判定
- 判定結果は `対象外`、`反映済み`、`未反映`、`不明`、`エラー` として保存
- コメントを編集した場合は AI 確認状態を未確認に戻す

### ノート機能

- Markdown ファイルをアップロードしてノートとして管理
- ノートの編集、追記、文書差し替え
- ノートのメンバー共有、公開リンク発行
- 制作メモ、確認観点、議事録などを同じワークスペース内で扱える

### 表示言語切り替え

- アカウント設定画面から、日本語と英語をユーザーごとに選択
- 選択した言語はユーザー設定として保存
- 共通ヘッダー、アカウント設定、ダッシュボード、ノート一覧などの基本 UI に反映
- 未設定時や不正な値の場合は日本語を使用
- 言語設定は `webpatch_ai_user_preferences.app_language` に保存

## 基本的な利用フロー

1. ユーザー登録、ログイン
2. ダッシュボードから HTML サイトを登録
   - ZIP アップロード
   - または CSV の URL リストから登録
3. プロジェクト画面で対象ページを確認
4. プレビュー上の要素を選び、コメントを追加
5. 制作者が返信、修正、ステータス更新
6. 必要に応じて AI 確認で修正反映状況をチェック
7. コメントシートや公開リンクを使って関係者と共有
8. 対応完了したコメントを解決済みにする

## 想定ユーザー

- Web 制作会社
- フリーランスの Web 制作者
- デザイナー、ディレクター、エンジニア
- クライアント確認を受ける制作チーム
- 公開前サイトのレビューを管理したい社内 Web 担当者

## 技術構成

- PHP
- MySQL
- HTML/CSS/JavaScript
- PHP セッション認証
- PDO によるデータベース接続
- ZIP 展開による静的サイト取り込み
- cURL による URL 取り込み、AI API 接続

外部 npm パッケージやフロントエンドビルド環境には依存していません。

## インストール手順

WebPatch は Composer や npm を使わない、素の PHP/MySQL アプリです。基本的には、PHP が動く Web サーバーにファイルを配置し、MySQL と保存ディレクトリを用意すれば動作します。

### 1. 動作要件

- PHP 8 系推奨
- MySQL または MySQL 互換 DB
- Apache または Nginx + PHP-FPM
- Git

必要な PHP 拡張:

- `pdo_mysql`
- `zip`
- `curl`
- `mbstring`
- `dom`
- `openssl`
- `fileinfo`

### 2. リポジトリを取得

Web サーバーの公開ディレクトリ、または公開ディレクトリにリンクできる場所で clone します。

```bash
git clone https://github.com/shotaueyama/webpatch.git
cd webpatch
```

`base_url` を `/webpatch` にする場合は、`https://example.com/webpatch/` でこのディレクトリが配信されるように配置します。

`_app.php` は `webpatch_app/bootstrap.php` を読み込みます。標準構成では、同じディレクトリ内の `webpatch_app` を参照します。

### 3. 設定ファイルを作成

`webpatch_app/config.example.php` をコピーして `webpatch_app/config.php` を作成します。

```bash
cp webpatch_app/config.example.php webpatch_app/config.php
```

環境に合わせて `webpatch_app/config.php` を編集します。

```php
<?php

return [
    'base_url' => '/webpatch',
    'storage_root' => '/var/www/webpatch_storage',
    'key_encryption_secret' => '十分に長いランダム文字列',
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=webpatch;charset=utf8mb4',
        'user' => 'webpatch_user',
        'password' => 'DBパスワード',
        'table_prefix' => 'webpatch_',
    ],
];
```

`config.php` には DB パスワードや暗号化キーが含まれるため、Git 管理には含めません。このリポジトリでは `.gitignore` に追加済みです。

### 4. データベースを作成

MySQL にデータベースとユーザーを作成します。

```sql
CREATE DATABASE webpatch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'webpatch_user'@'localhost' IDENTIFIED BY 'DBパスワード';
GRANT ALL PRIVILEGES ON webpatch.* TO 'webpatch_user'@'localhost';
FLUSH PRIVILEGES;
```

基本スキーマを流し込みます。

```bash
mysql -u webpatch_user -p webpatch < webpatch_app/schema.sql
```

一部の追加テーブルやカラムは、アプリ実行時に自動作成、追加されます。

表示言語設定では `webpatch_ai_user_preferences` テーブルの `app_language` カラムを使用します。既存環境では、アプリ実行時に不足カラムが自動追加されます。

### 5. ストレージディレクトリを作成

`storage_root` に指定したディレクトリを作成し、Web サーバー実行ユーザーが読み書きできるようにします。

Ubuntu/Debian の Apache や PHP-FPM で `www-data` を使う例:

```bash
sudo mkdir -p /var/www/webpatch_storage
sudo chown -R www-data:www-data /var/www/webpatch_storage
sudo chmod 750 /var/www/webpatch_storage
```

保存される主なデータ:

- アップロード、取得した HTML サイト
- 元ファイルのスナップショット
- コメント添付画像
- PHP セッションファイル
- URL 登録サイトの管理メタデータ

### 6. Web サーバーで公開

このリポジトリの PHP ファイル群を Web サーバーから配信します。

Apache の例:

```apache
Alias /webpatch /var/www/webpatch

<Directory /var/www/webpatch>
    Options -Indexes
    AllowOverride All
    Require all granted
</Directory>
```

Nginx + PHP-FPM の例:

```nginx
location /webpatch/ {
    alias /var/www/webpatch/;
    index index.php;
    try_files $uri $uri/ /webpatch/index.php?$query_string;
}

location ~ ^/webpatch/(.+\.php)$ {
    alias /var/www/webpatch/$1;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/webpatch/$1;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
}
```

実際の PHP-FPM ソケットや公開パスはサーバー環境に合わせて変更してください。

### 7. PHP アップロード設定

`.user.ini` ではアップロードサイズを調整しています。

```ini
upload_max_filesize=100M
post_max_size=110M
max_file_uploads=20
```

サーバー側でも同等の設定が反映されるようにしてください。

### 8. 初回アクセス

ブラウザで `https://example.com/webpatch/` を開き、最初のユーザーを登録します。

ユーザー登録数は `WEBPATCH_MAX_USERS` で制限されています。現在のコードでは最大 5 ユーザーです。

### 9. AI 機能を使う場合

アカウント設定画面から OpenAI、Gemini、Grok の API キーを登録します。

AI 確認機能は、コメント内容と現在の HTML を照合して反映状況を判定する機能です。API キーが未設定でも、通常のサイト登録、コメント、共有、公開レビューは利用できます。

## 主要ファイル

- `dashboard.php`: サイト一覧、ZIP/URL 登録
- `project.php`: ログインユーザー向けレビュー画面
- `public-project.php`: 公開リンク向けレビュー画面
- `comments.php`: ログインユーザー向けコメント API
- `public-comments.php`: ゲスト向けコメント API
- `comment-sheet.php`: コメントのシート表示
- `sheet-api.php`: コメントシート外部 API
- `ai-check-comments.php`: AI によるコメント反映確認
- `account.php`: アカウント、表示言語、AI API 設定
- `notes.php`, `note.php`: ノート一覧、ノート詳細
- `webpatch_app/bootstrap.php`: 共通ロジック
- `webpatch_app/layout.php`: 共通レイアウト
- `webpatch_app/schema.sql`: 基本 DB スキーマ

## セキュリティと運用上の注意

- `webpatch_app/config.php` は公開リポジトリに含めないでください。
- `key_encryption_secret` は推測されにくい十分に長いランダム文字列に変更してください。
- AI API キーは AES-256-GCM で暗号化して保存されますが、暗号化キーと DB の両方を安全に管理してください。
- URL 登録サイトで Basic 認証を使用した場合、認証情報はストレージ内の URL 管理メタデータに保存されます。ストレージディレクトリの権限管理を徹底してください。
- 公開リンクはトークンを知っている人がアクセスできます。不要になったら無効化または再発行してください。
- アップロード ZIP から PHP や実行系拡張子は除外されますが、Web サーバー側でもストレージ配下を直接実行できない構成にしてください。
- コメント添付画像や取得 HTML には機密情報が含まれる可能性があります。バックアップ、削除、アクセス権限の運用ルールを決めてください。

## このツールが向いている場面

- 公開前サイトのクライアントレビュー
- LP やコーポレートサイトの修正確認
- 静的 HTML 納品物の確認
- 複数ページにまたがる修正依頼の整理
- 制作会社と発注者の間の確認コミュニケーション
- スプレッドシートだけでは指摘箇所が伝わりにくいレビュー

## English

[Japanese](#webpatch) | English

WebPatch is a communication support tool for web production teams. It helps teams collect feedback, manage change requests, and confirm whether requested changes have been reflected in a built website.

You can upload an HTML site or import pages from URLs, preview the site in a browser-like review screen, and leave comments directly on page elements. The goal is to reduce common production handoff problems such as unclear feedback locations, scattered revision requests, and uncertainty about whether a fix has been completed.

### Problems WebPatch Helps Solve

Website feedback is often spread across email, chat, spreadsheets, screenshots, and meeting notes. That creates several recurring problems:

- Reviewers need to explain the target page, viewport, screenshot, and affected element in words
- Developers spend time finding what each comment refers to
- Replies, status updates, and final confirmation are split across multiple tools
- Sharing unpublished or exported HTML sites is cumbersome
- It is hard to confirm whether older feedback has actually been reflected

WebPatch brings HTML preview, element-level comments, status management, public review links, and AI-assisted reflection checks into one workspace.

### Key Features

#### Site Registration

- Upload a ZIP file containing HTML and assets
- Import pages from a CSV list of URLs on the same domain
- Import and refresh sites protected by Basic authentication
- Refresh registered URL-based sites by fetching the latest HTML
- Replace a single page or the entire site for ZIP-based projects

#### Browser-Based Review

- Preview registered HTML pages inside an iframe
- Switch preview width between desktop, tablet, and mobile
- Move between pages from the page list
- Rewrite relative links, images, CSS, and JavaScript paths through WebPatch routes
- Open the preview in a separate tab for full-screen review

#### Comments and Communication

- Add comments directly to page elements
- Reply in comment threads
- Edit, delete, resolve, and reopen comments
- Mark comments as waiting for confirmation
- Attach images to comments
- Show unresolved comment indicators per page
- Support both logged-in members and guest reviewers

#### Sharing and Public Review

- Share projects with registered users
- Grant comment-only or editable access
- Send invite links to unregistered users
- Create public links for guest review without login
- Disable or regenerate public links

#### Comment Sheet and External API

- View comments in a sheet-style interface
- Check status, desired due date, and AI check results in one place
- Issue API tokens for external tools
- Fetch and update comments through the sheet API
- Manage `todo`, `doing`, and `done` statuses

#### AI Reflection Check

- Store OpenAI, Gemini, and Grok API keys per user
- Compare comment requests with the current HTML
- Save results as `not applicable`, `reflected`, `not reflected`, `uncertain`, or `error`
- Reset the AI check status when a comment is edited

#### Notes

- Upload Markdown files as notes
- Edit, append to, and replace note content
- Share notes with members
- Create public note links
- Keep production notes, review criteria, and meeting notes in the same workspace

#### Display Language

- Choose Japanese or English per user from the account settings screen
- Store the selected language as a user preference
- Apply the language to core UI areas such as the shared header, account settings, dashboard, and notes list
- Fall back to Japanese when no language is set or an invalid value is found
- Store the setting in `webpatch_ai_user_preferences.app_language`

### Basic Workflow

1. Register a user and log in
2. Register an HTML site from the dashboard
   - Upload a ZIP file
   - Or import pages from a CSV URL list
3. Open a project and review the target page
4. Select an element in the preview and add a comment
5. Reply, update, fix, and manage comment status
6. Run AI checks when you want to confirm whether comments have been reflected
7. Share progress through the comment sheet or public links
8. Mark completed comments as resolved

### Intended Users

- Web production companies
- Freelance web developers and designers
- Designers, directors, and engineers
- Teams that collect client review feedback
- In-house website owners reviewing unpublished pages

### Technical Stack

- PHP
- MySQL
- HTML/CSS/JavaScript
- PHP session authentication
- PDO database access
- ZIP extraction for static site import
- cURL for URL import and AI API calls

WebPatch does not depend on Composer, npm, or a frontend build pipeline.

### Installation

WebPatch is a plain PHP/MySQL application. Install it by placing the files on a PHP-capable web server, creating a MySQL database, and preparing a writable storage directory.

#### 1. Requirements

- PHP 8 or later recommended
- MySQL or a MySQL-compatible database
- Apache or Nginx + PHP-FPM
- Git

Required PHP extensions:

- `pdo_mysql`
- `zip`
- `curl`
- `mbstring`
- `dom`
- `openssl`
- `fileinfo`

#### 2. Clone the Repository

Clone the repository into your web root or another location that your web server can expose.

```bash
git clone https://github.com/shotaueyama/webpatch.git
cd webpatch
```

If `base_url` is `/webpatch`, configure your server so this directory is available at `https://example.com/webpatch/`.

`_app.php` loads `webpatch_app/bootstrap.php`. In the default layout, `webpatch_app` lives inside the same repository directory.

#### 3. Create the Configuration File

Copy the example config file:

```bash
cp webpatch_app/config.example.php webpatch_app/config.php
```

Edit `webpatch_app/config.php` for your environment.

```php
<?php

return [
    'base_url' => '/webpatch',
    'storage_root' => '/var/www/webpatch_storage',
    'key_encryption_secret' => 'use-a-long-random-secret-string',
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=webpatch;charset=utf8mb4',
        'user' => 'webpatch_user',
        'password' => 'database-password',
        'table_prefix' => 'webpatch_',
    ],
];
```

`config.php` contains database credentials and the encryption secret, so do not commit it to Git. It is already included in `.gitignore`.

#### 4. Create the Database

Create a database and user in MySQL.

```sql
CREATE DATABASE webpatch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'webpatch_user'@'localhost' IDENTIFIED BY 'database-password';
GRANT ALL PRIVILEGES ON webpatch.* TO 'webpatch_user'@'localhost';
FLUSH PRIVILEGES;
```

Import the base schema:

```bash
mysql -u webpatch_user -p webpatch < webpatch_app/schema.sql
```

Some additional tables and columns are created automatically at runtime.

The display language setting uses the `app_language` column on the `webpatch_ai_user_preferences` table. Existing installations add the missing column automatically at runtime.

#### 5. Create the Storage Directory

Create the directory configured as `storage_root` and make it writable by the web server user.

Example for Ubuntu/Debian using `www-data`:

```bash
sudo mkdir -p /var/www/webpatch_storage
sudo chown -R www-data:www-data /var/www/webpatch_storage
sudo chmod 750 /var/www/webpatch_storage
```

Stored data includes:

- Uploaded or fetched HTML sites
- Original file snapshots
- Comment attachment images
- PHP session files
- URL import metadata

#### 6. Expose the App Through a Web Server

Serve the PHP files in this repository through Apache or Nginx.

Apache example:

```apache
Alias /webpatch /var/www/webpatch

<Directory /var/www/webpatch>
    Options -Indexes
    AllowOverride All
    Require all granted
</Directory>
```

Nginx + PHP-FPM example:

```nginx
location /webpatch/ {
    alias /var/www/webpatch/;
    index index.php;
    try_files $uri $uri/ /webpatch/index.php?$query_string;
}

location ~ ^/webpatch/(.+\.php)$ {
    alias /var/www/webpatch/$1;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/webpatch/$1;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
}
```

Adjust paths and the PHP-FPM socket for your server.

#### 7. Configure PHP Upload Limits

`.user.ini` contains upload-related defaults:

```ini
upload_max_filesize=100M
post_max_size=110M
max_file_uploads=20
```

Make sure equivalent limits are active in your server-level PHP configuration.

#### 8. First Access

Open `https://example.com/webpatch/` in a browser and register the first user.

User registration is limited by `WEBPATCH_MAX_USERS`. The current code allows up to 5 users.

#### 9. Optional AI Setup

To use AI-assisted reflection checks, open the account settings screen and register API keys for OpenAI, Gemini, or Grok.

The core features, including site registration, comments, sharing, and public review links, work without AI API keys.

### Important Files

- `dashboard.php`: Site list and ZIP/URL registration
- `project.php`: Review screen for logged-in users
- `public-project.php`: Public review screen
- `comments.php`: Comment API for logged-in users
- `public-comments.php`: Guest comment API
- `comment-sheet.php`: Sheet-style comment view
- `sheet-api.php`: External comment sheet API
- `ai-check-comments.php`: AI-assisted reflection checks
- `account.php`: Account, display language, and AI API settings
- `notes.php`, `note.php`: Note list and note detail
- `webpatch_app/bootstrap.php`: Shared application logic
- `webpatch_app/layout.php`: Shared layout
- `webpatch_app/schema.sql`: Base database schema

### Security and Operations Notes

- Do not commit `webpatch_app/config.php`.
- Replace `key_encryption_secret` with a long, random secret.
- AI API keys are encrypted with AES-256-GCM, but both the encryption key and database must be protected.
- If you import a site using Basic authentication, the credentials are stored in URL import metadata under the storage directory. Protect storage permissions carefully.
- Anyone with a public link token can access that public review page. Disable or regenerate public links when they are no longer needed.
- PHP and other executable extensions are excluded from uploaded ZIP files, but your web server should also prevent direct execution from the storage directory.
- Uploaded HTML, comment images, and fetched site files may contain confidential information. Define backup, deletion, and access-control rules before production use.

### Good Use Cases

- Client review for unpublished websites
- Landing page and corporate site revision checks
- Review of static HTML deliverables
- Managing feedback across multiple pages
- Communication between web production teams and clients
- Reviews where spreadsheets alone make it hard to identify the exact target element

## ライセンス

現時点ではライセンス未設定です。利用、再配布、公開範囲を決める場合は、別途 `LICENSE` を追加してください。

## License

No license has been set yet. Add a `LICENSE` file before defining usage, redistribution, or public release terms.
