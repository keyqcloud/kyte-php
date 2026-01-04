<?php
/**
 * Japanese (日本語) Translation File
 *
 * NOTE: This file contains PLACEHOLDER translations.
 * Professional translation recommended for production use.
 *
 * @package Kyte
 * @version 4.0.0
 * @language Japanese (ja / 日本語)
 * @status DRAFT - Requires professional translation
 */

return [
    // =========================================================================
    // COMMON ERRORS (一般的なエラー)
    // =========================================================================
    'error.not_found' => 'レコードが見つかりません',
    'error.unauthorized' => '不正なアクセス',
    'error.forbidden' => 'アクセスが禁止されています',
    'error.invalid_request' => '無効なリクエスト',
    'error.validation_failed' => '検証に失敗しました: {field}',
    'error.required_field' => '{field}は必須です',
    'error.invalid_format' => '{field}の形式が無効です',
    'error.duplicate_entry' => '{field}は既に存在します',
    'error.database_error' => 'データベースエラーが発生しました',
    'error.server_error' => '内部サーバーエラー',
    'error.not_implemented' => '機能が実装されていません',
    'error.rate_limit_exceeded' => 'レート制限を超えました。後でもう一度お試しください',
    'error.service_unavailable' => 'サービスが一時的に利用できません',
    'error.invalid_credentials' => '認証情報が無効です',
    'error.session_expired' => 'セッションの有効期限が切れました。再度ログインしてください',
    'error.permission_denied' => '権限がありません',
    'error.invalid_token' => 'トークンが無効または期限切れです',
    'error.file_too_large' => 'ファイルサイズが許容される最大値を超えています',
    'error.unsupported_file_type' => 'サポートされていないファイル形式です',
    'error.upload_failed' => 'ファイルのアップロードに失敗しました',

    // =========================================================================
    // SUCCESS MESSAGES (成功メッセージ)
    // =========================================================================
    'success.created' => '{model}が正常に作成されました',
    'success.updated' => '{model}が正常に更新されました',
    'success.deleted' => '{model}が正常に削除されました',
    'success.saved' => '変更が正常に保存されました',
    'success.sent' => '{item}が正常に送信されました',
    'success.uploaded' => 'ファイルが正常にアップロードされました',
    'success.operation_complete' => '操作が正常に完了しました',

    // =========================================================================
    // AUTHENTICATION & SESSIONS (認証とセッション)
    // =========================================================================
    'auth.login_success' => 'ログインに成功しました',
    'auth.logout_success' => 'ログアウトしました',
    'auth.invalid_credentials' => 'メールアドレスまたはパスワードが無効です',
    'auth.account_disabled' => 'アカウントが無効になっています',
    'auth.session_expired' => 'セッションの有効期限が切れました',
    'auth.session_invalid' => '無効なセッション',
    'auth.password_reset_sent' => 'パスワードリセットの手順をメールで送信しました',
    'auth.password_reset_success' => 'パスワードのリセットに成功しました',
    'auth.password_reset_invalid' => 'パスワードリセットトークンが無効または期限切れです',
    'auth.email_not_found' => 'メールアドレスが見つかりません',

    // =========================================================================
    // VALIDATION (検証)
    // =========================================================================
    'validation.required' => '{field}は必須です',
    'validation.email' => 'メールアドレスが無効です',
    'validation.min_length' => '{field}は少なくとも{min}文字である必要があります',
    'validation.max_length' => '{field}は{max}文字を超えてはいけません',
    'validation.numeric' => '{field}は数値である必要があります',
    'validation.integer' => '{field}は整数である必要があります',
    'validation.positive' => '{field}は正の値である必要があります',
    'validation.url' => 'URLが無効です',
    'validation.date' => '日付形式が無効です',
    'validation.unique' => '{field}は既に存在します',
    'validation.in' => '{field}の値が無効です',
    'validation.confirmed' => 'パスワード確認が一致しません',
    'validation.min_value' => '{field}は少なくとも{min}である必要があります',
    'validation.max_value' => '{field}は{max}を超えてはいけません',

    // =========================================================================
    // NOTE: Additional translations needed below
    // 注意: 以下に追加の翻訳が必要です
    // Professional translator should complete these entries
    // =========================================================================

    // MODELS & CRUD
    'model.create_failed' => '{model}の作成に失敗しました',
    'model.update_failed' => '{model}の更新に失敗しました',
    'model.delete_failed' => '{model}の削除に失敗しました',
    'model.not_found' => '{model}が見つかりません',
    'model.already_exists' => '{model}は既に存在します',
    'model.invalid_id' => '無効な{model} ID',

    // CRON JOBS
    'cron.job_not_found' => 'Cronジョブが見つかりません',
    'cron.job_disabled' => 'Cronジョブが無効になっています',
    'cron.job_running' => 'Cronジョブは既に実行中です',
    'cron.job_triggered' => 'Cronジョブが正常にトリガーされました',
    'cron.job_in_dlq' => 'Cronジョブがデッドレターキューにあります',
    'cron.job_recovered' => 'Cronジョブがデッドレターキューから回復されました',
    'cron.execution_failed' => 'Cronジョブの実行に失敗しました',
    'cron.timeout' => 'Cronジョブの実行がタイムアウトしました',
    'cron.invalid_schedule' => 'スケジュール設定が無効です',
    'cron.code_invalid' => 'ジョブコードが無効です',

    // FILES & STORAGE
    'file.upload_success' => 'ファイルが正常にアップロードされました',
    'file.upload_failed' => 'ファイルのアップロードに失敗しました',
    'file.not_found' => 'ファイルが見つかりません',
    'file.delete_success' => 'ファイルが正常に削除されました',
    'file.delete_failed' => 'ファイルの削除に失敗しました',
    'file.invalid_type' => '無効なファイル形式',
    'file.too_large' => 'ファイルが大きすぎます。最大サイズ: {max}',
    'file.empty' => 'ファイルが空です',

    // AWS SERVICES
    'aws.invalid_credentials' => 'AWS認証情報が無効です',
    'aws.operation_failed' => 'AWS操作に失敗しました: {operation}',
    'aws.s3_upload_failed' => 'S3へのアップロードに失敗しました',
    'aws.s3_delete_failed' => 'S3からの削除に失敗しました',
    'aws.ses_send_failed' => 'メールの送信に失敗しました',
    'aws.cloudfront_invalid' => 'CloudFrontディストリビューションが見つかりません',

    // API & REQUESTS
    'api.invalid_signature' => 'API署名が無効です',
    'api.missing_header' => '必須ヘッダーがありません: {header}',
    'api.invalid_method' => '無効なHTTPメソッド。期待値: {expected}',
    'api.rate_limited' => 'APIレート制限を超えました',
    'api.invalid_json' => 'リクエスト本文のJSONが無効です',
    'api.missing_parameter' => '必須パラメータがありません: {param}',
    'api.invalid_parameter' => '無効なパラメータ: {param}',

    // DATABASE
    'db.connection_failed' => 'データベース接続に失敗しました',
    'db.query_failed' => 'データベースクエリに失敗しました',
    'db.transaction_failed' => 'データベーストランザクションに失敗しました',
    'db.integrity_violation' => 'データベース整合性制約違反',
    'db.duplicate_key' => '重複キーエラー',

    // USER & ACCOUNT
    'user.not_found' => 'ユーザーが見つかりません',
    'user.created' => 'ユーザーが正常に作成されました',
    'user.updated' => 'ユーザーが正常に更新されました',
    'user.deleted' => 'ユーザーが正常に削除されました',
    'user.email_exists' => 'メールアドレスは既に存在します',
    'user.invalid_email' => '無効なメールアドレス',
    'user.password_too_short' => 'パスワードは少なくとも{min}文字である必要があります',
    'user.password_mismatch' => 'パスワードが一致しません',
    'account.not_found' => 'アカウントが見つかりません',
    'account.suspended' => 'アカウントが停止されています',

    // GENERAL ACTIONS
    'action.confirm_delete' => '本当に削除しますか？',
    'action.confirm_action' => '続行してもよろしいですか？',
    'action.cannot_undo' => 'この操作は元に戻せません',
    'action.processing' => '処理中...',
    'action.please_wait' => 'お待ちください...',
    'action.loading' => '読み込み中...',
    'action.saving' => '保存中...',
    'action.deleting' => '削除中...',

    // DATES & TIMES
    'date.today' => '今日',
    'date.yesterday' => '昨日',
    'date.tomorrow' => '明日',
    'date.last_week' => '先週',
    'date.next_week' => '来週',
    'date.last_month' => '先月',
    'date.next_month' => '来月',

    // PAGINATION
    'pagination.showing' => '{total}件中{from}から{to}を表示',
    'pagination.no_results' => '結果が見つかりません',
    'pagination.per_page' => 'ページあたり',
    'pagination.next' => '次へ',
    'pagination.previous' => '前へ',
    'pagination.first' => '最初',
    'pagination.last' => '最後',
];
