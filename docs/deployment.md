# WebPatch 本番デプロイ手順

この文書は、`https://cognify.works/webpatch/` で稼働している WebPatch の本番構成と、ローカルから安全にファイルを反映する手順をまとめたものです。

## 本番環境

| 項目 | 値 |
| --- | --- |
| 本番URL | `https://cognify.works/webpatch/` |
| サーバー | `220.158.17.125` |
| SSHユーザー | `root` |
| SSH秘密鍵 | `/Users/shotaueyama/.ssh/id_ed25519` |
| OS | Ubuntu 24.04 |
| Webサーバー | Nginx 1.24 |
| PHP | PHP 8.3 / PHP-FPM |
| 公開ファイル | `/var/www/cognify/cognify/webpatch` |
| 非公開アプリコード | `/var/www/cognify/webpatch_app` |
| サイト・添付・セッション保存領域 | `/var/www/cognify/webpatch_storage` |
| 本番設定 | `/var/www/cognify/webpatch_app/config.php` |
| データベース | `quadra_cognify`、テーブル接頭辞 `webpatch_` |

本番URLにはNginxのBasic認証が設定されています。Basic認証情報、DBパスワード、APIキー、暗号化キーはこの文書には記載しません。

## 配置構成

ローカルのルート直下にあるPHP、CSS、画像などは公開ディレクトリへ配置します。

```text
ローカル                           本番
project.php                     -> /var/www/cognify/cognify/webpatch/project.php
styles.css                      -> /var/www/cognify/cognify/webpatch/styles.css
asset.php                       -> /var/www/cognify/cognify/webpatch/asset.php
webpatch_app/bootstrap.php      -> /var/www/cognify/webpatch_app/bootstrap.php
webpatch_app/layout.php         -> /var/www/cognify/webpatch_app/layout.php
```

`_app.php` は公開ディレクトリから `../../webpatch_app` を解決し、公開ルート外の `bootstrap.php` を読み込みます。

次のファイルや領域は通常のコードデプロイで上書きしません。

- `/var/www/cognify/webpatch_app/config.php`
- `/var/www/cognify/webpatch_storage`
- NginxのBasic認証ファイル
- 本番DB

## デプロイ前確認

作業ディレクトリへ移動します。

```bash
cd "/Users/shotaueyama/Documents/New project 7"
```

変更対象を確認します。

```bash
git status --short
git diff --check
git diff -- path/to/file
```

変更したPHPは全ファイルを構文確認します。

```bash
php -l project.php
php -l webpatch_app/bootstrap.php
```

本番へ送るファイルを明示し、関係のないローカル変更を一緒に送らないようにします。依存インストールやビルドは不要です。

## SSH接続確認

```bash
ssh -i /Users/shotaueyama/.ssh/id_ed25519 \
  -o BatchMode=yes \
  root@220.158.17.125 'hostname && php -v | head -1'
```

## 公開ファイルのデプロイ

例として `project.php` を反映します。先に本番ファイルを時刻付きでバックアップします。

```bash
STAMP=$(date +%Y%m%d-%H%M%S)

ssh -i /Users/shotaueyama/.ssh/id_ed25519 \
  -o BatchMode=yes \
  root@220.158.17.125 \
  "cp /var/www/cognify/cognify/webpatch/project.php \
      /var/www/cognify/cognify/webpatch/project.php.bak-$STAMP"

scp -i /Users/shotaueyama/.ssh/id_ed25519 \
  -o BatchMode=yes \
  project.php \
  root@220.158.17.125:/var/www/cognify/cognify/webpatch/project.php

ssh -i /Users/shotaueyama/.ssh/id_ed25519 \
  -o BatchMode=yes \
  root@220.158.17.125 \
  'chown www-data:www-data /var/www/cognify/cognify/webpatch/project.php &&
   chmod 0644 /var/www/cognify/cognify/webpatch/project.php &&
   php -l /var/www/cognify/cognify/webpatch/project.php'
```

CSSも同様にバックアップしてから転送します。PHP構文確認は不要ですが、所有者と権限を確認します。

```bash
scp -i /Users/shotaueyama/.ssh/id_ed25519 \
  -o BatchMode=yes \
  styles.css \
  root@220.158.17.125:/var/www/cognify/cognify/webpatch/styles.css

ssh -i /Users/shotaueyama/.ssh/id_ed25519 \
  -o BatchMode=yes \
  root@220.158.17.125 \
  'chown www-data:www-data /var/www/cognify/cognify/webpatch/styles.css &&
   chmod 0644 /var/www/cognify/cognify/webpatch/styles.css'
```

## 非公開アプリコードのデプロイ

例として `webpatch_app/bootstrap.php` を反映します。

```bash
STAMP=$(date +%Y%m%d-%H%M%S)

ssh -i /Users/shotaueyama/.ssh/id_ed25519 \
  -o BatchMode=yes \
  root@220.158.17.125 \
  "cp /var/www/cognify/webpatch_app/bootstrap.php \
      /var/www/cognify/webpatch_app/bootstrap.php.bak-$STAMP"

scp -i /Users/shotaueyama/.ssh/id_ed25519 \
  -o BatchMode=yes \
  webpatch_app/bootstrap.php \
  root@220.158.17.125:/var/www/cognify/webpatch_app/bootstrap.php

ssh -i /Users/shotaueyama/.ssh/id_ed25519 \
  -o BatchMode=yes \
  root@220.158.17.125 \
  'chown root:www-data /var/www/cognify/webpatch_app/bootstrap.php &&
   chmod 0640 /var/www/cognify/webpatch_app/bootstrap.php &&
   php -l /var/www/cognify/webpatch_app/bootstrap.php'
```

PHP-FPMの実行ユーザー `www-data` がグループ権限で読み取れる状態にします。`config.php` は転送対象に含めません。

## 複数ファイルを反映する場合

ファイルごとに配置先を明示して `scp` します。ディレクトリ全体を同期すると、本番専用設定や保存データを消す危険があるため、通常のデプロイでは使用しません。

```bash
scp -i /Users/shotaueyama/.ssh/id_ed25519 project.php styles.css \
  root@220.158.17.125:/var/www/cognify/cognify/webpatch/

scp -i /Users/shotaueyama/.ssh/id_ed25519 \
  webpatch_app/bootstrap.php webpatch_app/layout.php \
  root@220.158.17.125:/var/www/cognify/webpatch_app/
```

転送後は各ファイルの所有者、権限、PHP構文を個別に確認してください。`rsync` は削除や意図しない同期の影響が大きいため使用しません。

## デプロイ後確認

### ファイルとPHP構文

```bash
ssh -i /Users/shotaueyama/.ssh/id_ed25519 root@220.158.17.125 \
  'stat -c "%U:%G %a %n" \
     /var/www/cognify/cognify/webpatch/project.php \
     /var/www/cognify/cognify/webpatch/styles.css \
     /var/www/cognify/webpatch_app/bootstrap.php &&
   php -l /var/www/cognify/cognify/webpatch/project.php &&
   php -l /var/www/cognify/webpatch_app/bootstrap.php'
```

### HTTP確認

Basic認証値は環境変数で渡し、コマンド履歴へ直接残さないようにします。

```bash
read -r -p 'Basic ID: ' WEBPATCH_BASIC_ID
read -r -s -p 'Basic password: ' WEBPATCH_BASIC_PASSWORD
echo

curl -sS -o /dev/null -w '%{http_code}\n' \
  -u "$WEBPATCH_BASIC_ID:$WEBPATCH_BASIC_PASSWORD" \
  https://cognify.works/webpatch/login
```

期待値は `200` です。ログイン必須ページは、未ログイン状態では `302` になる場合があります。

ブラウザでは最低限、次を確認します。

- ログイン、サイト一覧、アカウント設定が開く
- 対象プロジェクトのプレビューが表示される
- CSS、画像、動画、共通ヘッダー、共通フッターが表示される
- 管理画面と公開リンクの両方が動く
- 変更したフォーム/APIの成功・エラー表示がJSONまたはトーストとして正しく返る
- PC、タブレット、スマホの表示幅が切り替わる

### ログ確認

```bash
ssh -i /Users/shotaueyama/.ssh/id_ed25519 root@220.158.17.125 \
  "tail -n 100 /var/log/nginx/error.log"

ssh -i /Users/shotaueyama/.ssh/id_ed25519 root@220.158.17.125 \
  "journalctl -u php8.3-fpm --since '15 minutes ago' --no-pager"
```

## ロールバック

不具合がある場合は、デプロイ時に作成したバックアップを対象ファイルへ戻します。

```bash
ssh -i /Users/shotaueyama/.ssh/id_ed25519 root@220.158.17.125

cp /var/www/cognify/webpatch_app/bootstrap.php.bak-YYYYMMDD-HHMMSS \
   /var/www/cognify/webpatch_app/bootstrap.php
chown root:www-data /var/www/cognify/webpatch_app/bootstrap.php
chmod 0640 /var/www/cognify/webpatch_app/bootstrap.php
php -l /var/www/cognify/webpatch_app/bootstrap.php
```

公開ファイルも同じ要領で `.bak-YYYYMMDD-HHMMSS` を戻し、`www-data:www-data`、`0644` に設定します。

## DB変更を含む場合

`bootstrap.php` の `ensure_*` 関数で不足テーブルやカラムを自動作成する変更があります。DB構造を変更するデプロイでは、事前に対象SQLを確認し、必要に応じてDBバックアップを取得します。

```bash
mysqldump --single-transaction quadra_cognify > /root/quadra_cognify-$(date +%Y%m%d-%H%M%S).sql
```

DBユーザー名やパスワードをコマンドへ直接記載しないでください。

## 運用上の注意

- 本番ストレージ内のHTMLをCLIから更新した場合、所有者を `www-data:www-data`、ファイル権限を `0640` に戻します。
- `webpatch_storage` を公開ディレクトリ配下へ移動しません。
- `config.php`、Basic認証情報、APIキー、DB認証情報をGitへ追加しません。
- デプロイ対象外のローカル変更を戻したり、まとめて本番へ送ったりしません。
- NginxやPHP-FPMの設定変更は、`nginx -t` またはPHP構文確認後にreloadします。
- サービス全体を止めるrestartは、reloadで対応できない場合に限ります。
