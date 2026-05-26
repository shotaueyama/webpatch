# WebPatch

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
- `account.php`: アカウント、AI API 設定
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

## ライセンス

現時点ではライセンス未設定です。利用、再配布、公開範囲を決める場合は、別途 `LICENSE` を追加してください。
