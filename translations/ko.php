<?php
/**
 * Korean (한국어) Translation File
 *
 * NOTE: This file contains PLACEHOLDER translations.
 * Professional translation recommended for production use.
 *
 * @package Kyte
 * @version 4.0.0
 * @language Korean (ko / 한국어)
 * @status DRAFT - Requires professional translation
 */

return [
    // =========================================================================
    // COMMON ERRORS (일반 오류)
    // =========================================================================
    'error.not_found' => '레코드를 찾을 수 없습니다',
    'error.unauthorized' => '무단 액세스',
    'error.forbidden' => '액세스가 금지되었습니다',
    'error.invalid_request' => '잘못된 요청',
    'error.validation_failed' => '유효성 검사 실패: {field}',
    'error.required_field' => '{field}은(는) 필수입니다',
    'error.invalid_format' => '{field}의 형식이 잘못되었습니다',
    'error.duplicate_entry' => '{field}이(가) 이미 존재합니다',
    'error.database_error' => '데이터베이스 오류가 발생했습니다',
    'error.server_error' => '내부 서버 오류',
    'error.not_implemented' => '기능이 구현되지 않았습니다',
    'error.rate_limit_exceeded' => '요청 한도를 초과했습니다. 나중에 다시 시도해주세요',
    'error.service_unavailable' => '서비스를 일시적으로 사용할 수 없습니다',
    'error.invalid_credentials' => '잘못된 인증 정보',
    'error.session_expired' => '세션이 만료되었습니다. 다시 로그인해주세요',
    'error.permission_denied' => '권한이 없습니다',
    'error.invalid_token' => '토큰이 잘못되었거나 만료되었습니다',
    'error.file_too_large' => '파일 크기가 허용된 최대값을 초과합니다',
    'error.unsupported_file_type' => '지원되지 않는 파일 형식입니다',
    'error.upload_failed' => '파일 업로드에 실패했습니다',

    // =========================================================================
    // SUCCESS MESSAGES (성공 메시지)
    // =========================================================================
    'success.created' => '{model}이(가) 성공적으로 생성되었습니다',
    'success.updated' => '{model}이(가) 성공적으로 업데이트되었습니다',
    'success.deleted' => '{model}이(가) 성공적으로 삭제되었습니다',
    'success.saved' => '변경사항이 성공적으로 저장되었습니다',
    'success.sent' => '{item}이(가) 성공적으로 전송되었습니다',
    'success.uploaded' => '파일이 성공적으로 업로드되었습니다',
    'success.operation_complete' => '작업이 성공적으로 완료되었습니다',

    // =========================================================================
    // AUTHENTICATION & SESSIONS (인증 및 세션)
    // =========================================================================
    'auth.login_success' => '로그인에 성공했습니다',
    'auth.logout_success' => '로그아웃했습니다',
    'auth.invalid_credentials' => '이메일 또는 비밀번호가 잘못되었습니다',
    'auth.account_disabled' => '계정이 비활성화되었습니다',
    'auth.session_expired' => '세션이 만료되었습니다',
    'auth.session_invalid' => '잘못된 세션',
    'auth.password_reset_sent' => '비밀번호 재설정 안내를 이메일로 전송했습니다',
    'auth.password_reset_success' => '비밀번호 재설정에 성공했습니다',
    'auth.password_reset_invalid' => '비밀번호 재설정 토큰이 잘못되었거나 만료되었습니다',
    'auth.email_not_found' => '이메일 주소를 찾을 수 없습니다',

    // =========================================================================
    // VALIDATION (유효성 검사)
    // =========================================================================
    'validation.required' => '{field}은(는) 필수입니다',
    'validation.email' => '이메일 주소가 잘못되었습니다',
    'validation.min_length' => '{field}은(는) 최소 {min}자 이상이어야 합니다',
    'validation.max_length' => '{field}은(는) {max}자를 초과할 수 없습니다',
    'validation.numeric' => '{field}은(는) 숫자여야 합니다',
    'validation.integer' => '{field}은(는) 정수여야 합니다',
    'validation.positive' => '{field}은(는) 양수여야 합니다',
    'validation.url' => 'URL이 잘못되었습니다',
    'validation.date' => '날짜 형식이 잘못되었습니다',
    'validation.unique' => '{field}이(가) 이미 존재합니다',
    'validation.in' => '{field}의 값이 잘못되었습니다',
    'validation.confirmed' => '비밀번호 확인이 일치하지 않습니다',
    'validation.min_value' => '{field}은(는) 최소 {min} 이상이어야 합니다',
    'validation.max_value' => '{field}은(는) {max}를 초과할 수 없습니다',

    // =========================================================================
    // NOTE: Additional translations needed below
    // 참고: 아래 추가 번역이 필요합니다
    // Professional translator should complete these entries
    // =========================================================================

    // MODELS & CRUD
    'model.create_failed' => '{model} 생성에 실패했습니다',
    'model.update_failed' => '{model} 업데이트에 실패했습니다',
    'model.delete_failed' => '{model} 삭제에 실패했습니다',
    'model.not_found' => '{model}을(를) 찾을 수 없습니다',
    'model.already_exists' => '{model}이(가) 이미 존재합니다',
    'model.invalid_id' => '잘못된 {model} ID',

    // CRON JOBS
    'cron.job_not_found' => 'Cron 작업을 찾을 수 없습니다',
    'cron.job_disabled' => 'Cron 작업이 비활성화되었습니다',
    'cron.job_running' => 'Cron 작업이 이미 실행 중입니다',
    'cron.job_triggered' => 'Cron 작업이 성공적으로 트리거되었습니다',
    'cron.job_in_dlq' => 'Cron 작업이 데드 레터 큐에 있습니다',
    'cron.job_recovered' => 'Cron 작업이 데드 레터 큐에서 복구되었습니다',
    'cron.execution_failed' => 'Cron 작업 실행에 실패했습니다',
    'cron.timeout' => 'Cron 작업 실행 시간이 초과되었습니다',
    'cron.invalid_schedule' => '일정 구성이 잘못되었습니다',
    'cron.code_invalid' => '작업 코드가 잘못되었습니다',

    // FILES & STORAGE
    'file.upload_success' => '파일이 성공적으로 업로드되었습니다',
    'file.upload_failed' => '파일 업로드에 실패했습니다',
    'file.not_found' => '파일을 찾을 수 없습니다',
    'file.delete_success' => '파일이 성공적으로 삭제되었습니다',
    'file.delete_failed' => '파일 삭제에 실패했습니다',
    'file.invalid_type' => '잘못된 파일 형식',
    'file.too_large' => '파일이 너무 큽니다. 최대 크기: {max}',
    'file.empty' => '파일이 비어 있습니다',

    // AWS SERVICES
    'aws.invalid_credentials' => 'AWS 인증 정보가 잘못되었습니다',
    'aws.operation_failed' => 'AWS 작업에 실패했습니다: {operation}',
    'aws.s3_upload_failed' => 'S3 업로드에 실패했습니다',
    'aws.s3_delete_failed' => 'S3 삭제에 실패했습니다',
    'aws.ses_send_failed' => '이메일 전송에 실패했습니다',
    'aws.cloudfront_invalid' => 'CloudFront 배포를 찾을 수 없습니다',

    // API & REQUESTS
    'api.invalid_signature' => 'API 서명이 잘못되었습니다',
    'api.missing_header' => '필수 헤더가 누락되었습니다: {header}',
    'api.invalid_method' => '잘못된 HTTP 메서드입니다. 예상값: {expected}',
    'api.rate_limited' => 'API 요청 한도를 초과했습니다',
    'api.invalid_json' => '요청 본문의 JSON이 잘못되었습니다',
    'api.missing_parameter' => '필수 매개변수가 누락되었습니다: {param}',
    'api.invalid_parameter' => '잘못된 매개변수: {param}',

    // DATABASE
    'db.connection_failed' => '데이터베이스 연결에 실패했습니다',
    'db.query_failed' => '데이터베이스 쿼리에 실패했습니다',
    'db.transaction_failed' => '데이터베이스 트랜잭션에 실패했습니다',
    'db.integrity_violation' => '데이터베이스 무결성 제약 조건 위반',
    'db.duplicate_key' => '중복 키 오류',

    // USER & ACCOUNT
    'user.not_found' => '사용자를 찾을 수 없습니다',
    'user.created' => '사용자가 성공적으로 생성되었습니다',
    'user.updated' => '사용자가 성공적으로 업데이트되었습니다',
    'user.deleted' => '사용자가 성공적으로 삭제되었습니다',
    'user.email_exists' => '이메일 주소가 이미 존재합니다',
    'user.invalid_email' => '잘못된 이메일 주소',
    'user.password_too_short' => '비밀번호는 최소 {min}자 이상이어야 합니다',
    'user.password_mismatch' => '비밀번호가 일치하지 않습니다',
    'account.not_found' => '계정을 찾을 수 없습니다',
    'account.suspended' => '계정이 정지되었습니다',

    // GENERAL ACTIONS
    'action.confirm_delete' => '정말로 삭제하시겠습니까?',
    'action.confirm_action' => '계속하시겠습니까?',
    'action.cannot_undo' => '이 작업은 취소할 수 없습니다',
    'action.processing' => '처리 중...',
    'action.please_wait' => '잠시 기다려주세요...',
    'action.loading' => '로딩 중...',
    'action.saving' => '저장 중...',
    'action.deleting' => '삭제 중...',

    // DATES & TIMES
    'date.today' => '오늘',
    'date.yesterday' => '어제',
    'date.tomorrow' => '내일',
    'date.last_week' => '지난주',
    'date.next_week' => '다음주',
    'date.last_month' => '지난달',
    'date.next_month' => '다음달',

    // PAGINATION
    'pagination.showing' => '전체 {total}개 중 {from}부터 {to}까지 표시',
    'pagination.no_results' => '결과를 찾을 수 없습니다',
    'pagination.per_page' => '페이지당',
    'pagination.next' => '다음',
    'pagination.previous' => '이전',
    'pagination.first' => '처음',
    'pagination.last' => '마지막',
];
