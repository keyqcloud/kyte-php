<?php
	/* Base URL of the application */
	define('API_URL', $_SERVER['SERVER_NAME']);

    /* General Application Settings */
    define('APP_NAME', ''); // Name of the application
    define('SHIPYARD_URL', ''); // Base URL of Kyte Shipyard
	define('APP_EMAIL', ''); // Application email address
    define('APP_SES_REGION', ''); // AWS SES region for sending emails')
	define('SUPPORT_EMAIL', ''); // Support email address
	define('SUPPORT_PHONE', ''); // Support phone number
    define('APP_DATE_FORMAT', 'Y/m/d'); // Date format for the application

    define('KYTE_JS_CDN', 'https://cdn.keyqcloud.com/kyte/js/stable/kyte.js'); // URL for Kyte JavaScript CDN
    
    /* Base Path for API */
    define('APP_DIR', '/var/www/html'); // Example: /var/www/html

	/* AWS Integration */
	define('AWS_ACCESS_KEY_ID', ''); // AWS Access Key ID
	define('AWS_SECRET_KEY', ''); // AWS Secret Access Key
	define('AWS_KMS_KEYID', ''); // AWS KMS Key ID for encryption

    /* AWS SNS Queues */
    define('SNS_REGION', 'us-east-1'); // AWS region for SNS
    define('SNS_QUEUE_SITE_MANAGEMENT', ''); // SNS queue for site management
    define('SNS_KYTE_SHIPYARD_UPDATE', ''); // SNS queue for Kyte Shipyard updates

    /* Kyte Framework Specific Settings */
    define('KYTE_USE_SNS', true); // Enable/Disable AWS SNS integration
    define('DEBUG', false); // Enable/Disable debug mode
    define('S3_DEBUG', false); // Enable/Disable S3 debug mode
    define('ALLOW_ENC_HANDOFF', true); // Allow encrypted handoff
    define('ALLOW_MULTILOGON', false); // Allow multiple logins for the same user
    define('ALLOW_SAME_TXTOKEN', false); // Allow the same transaction token
    define('SESSION_TIMEOUT', 3600); // Session timeout in seconds
    define('SIGNATURE_TIMEOUT', 600); // Signature timeout in seconds
    define('USERNAME_FIELD', 'email'); // Username field for login
    define('PASSWORD_FIELD', 'password'); // Password field for login
    define('VERBOSE_LOG', false); // Enable/Disable verbose logging
    define('IS_PRIVATE', true); // Is the application private
    define('RETURN_NO_MODEL', true); // Return no model
    define('SESSION_RETURN_FK', true); // Return foreign keys in session
    define('PAGE_SIZE', 50); // Page size for pagination
    define('USE_SESSION_MAP', false); // Use session map
    define('CHECK_SYNTAX_ON_IMPORT', false); // Check syntax on import
    define('STRICT_TYPING', true); // Enable/Disable strict typing

    /* Database Integration */
    define('KYTE_DB_USERNAME', ''); // Database username
    define('KYTE_DB_PASSWORD', ''); // Database password
    define('KYTE_DB_HOST', ''); // Database host
    define('KYTE_DB_DATABASE', ''); // Database name
    define('KYTE_DB_CHARSET', 'utf8mb4'); // Database charset
    // Uncomment and specify CA cert bundle path if you wish to use SSL
    // define('KYTE_DB_CA_BUNDLE', '/etc/ssl/certs/global-bundle.cert');

    /* Optional Slack Integration for APM */
    // define('SLACK_ERROR_WEBHOOK', '<YOUR SLACK WEBHOOK>');

	/* Application Timezone */
	date_default_timezone_set("Asia/Tokyo"); // Set the default timezone for the application
	/* List of Available Timezones */
	// 'Pacific/Midway'       => "(GMT-11:00) Midway Island",
    // 'US/Samoa'             => "(GMT-11:00) Samoa",
    // 'US/Hawaii'            => "(GMT-10:00) Hawaii",
    // 'US/Alaska'            => "(GMT-09:00) Alaska",
    // 'US/Pacific'           => "(GMT-08:00) Pacific Time (US & Canada)",
    // 'America/Tijuana'      => "(GMT-08:00) Tijuana",
    // 'US/Arizona'           => "(GMT-07:00) Arizona",
    // 'US/Mountain'          => "(GMT-07:00) Mountain Time (US & Canada)",
    // 'America/Chihuahua'    => "(GMT-07:00) Chihuahua",
    // 'America/Mazatlan'     => "(GMT-07:00) Mazatlan",
    // 'America/Mexico_City'  => "(GMT-06:00) Mexico City",
    // 'America/Monterrey'    => "(GMT-06:00) Monterrey",
    // 'Canada/Saskatchewan'  => "(GMT-06:00) Saskatchewan",
    // 'US/Central'           => "(GMT-06:00) Central Time (US & Canada)",
    // 'US/Eastern'           => "(GMT-05:00) Eastern Time (US & Canada)",
    // 'US/East-Indiana'      => "(GMT-05:00) Indiana (East)",
    // 'America/Bogota'       => "(GMT-05:00) Bogota",
    // 'America/Lima'         => "(GMT-05:00) Lima",
    // 'America/Caracas'      => "(GMT-04:30) Caracas",
    // 'Canada/Atlantic'      => "(GMT-04:00) Atlantic Time (Canada)",
    // 'America/La_Paz'       => "(GMT-04:00) La Paz",
    // 'America/Santiago'     => "(GMT-04:00) Santiago",
    // 'Canada/Newfoundland'  => "(GMT-03:30) Newfoundland",
    // 'America/Buenos_Aires' => "(GMT-03:00) Buenos Aires",
    // 'Greenland'            => "(GMT-03:00) Greenland",
    // 'Atlantic/Stanley'     => "(GMT-02:00) Stanley",
    // 'Atlantic/Azores'      => "(GMT-01:00) Azores",
    // 'Atlantic/Cape_Verde'  => "(GMT-01:00) Cape Verde Is.",
    // 'Africa/Casablanca'    => "(GMT) Casablanca",
    // 'Europe/Dublin'        => "(GMT) Dublin",
    // 'Europe/Lisbon'        => "(GMT) Lisbon",
    // 'Europe/London'        => "(GMT) London",
    // 'Africa/Monrovia'      => "(GMT) Monrovia",
    // 'Europe/Amsterdam'     => "(GMT+01:00) Amsterdam",
    // 'Europe/Belgrade'      => "(GMT+01:00) Belgrade",
    // 'Europe/Berlin'        => "(GMT+01:00) Berlin",
    // 'Europe/Bratislava'    => "(GMT+01:00) Bratislava",
    // 'Europe/Brussels'      => "(GMT+01:00) Brussels",
    // 'Europe/Budapest'      => "(GMT+01:00) Budapest",
    // 'Europe/Copenhagen'    => "(GMT+01:00) Copenhagen",
    // 'Europe/Ljubljana'     => "(GMT+01:00) Ljubljana",
    // 'Europe/Madrid'        => "(GMT+01:00) Madrid",
    // 'Europe/Paris'         => "(GMT+01:00) Paris",
    // 'Europe/Prague'        => "(GMT+01:00) Prague",
    // 'Europe/Rome'          => "(GMT+01:00) Rome",
    // 'Europe/Sarajevo'      => "(GMT+01:00) Sarajevo",
    // 'Europe/Skopje'        => "(GMT+01:00) Skopje",
    // 'Europe/Stockholm'     => "(GMT+01:00) Stockholm",
    // 'Europe/Vienna'        => "(GMT+01:00) Vienna",
    // 'Europe/Warsaw'        => "(GMT+01:00) Warsaw",
    // 'Europe/Zagreb'        => "(GMT+01:00) Zagreb",
    // 'Europe/Athens'        => "(GMT+02:00) Athens",
    // 'Europe/Bucharest'     => "(GMT+02:00) Bucharest",
    // 'Africa/Cairo'         => "(GMT+02:00) Cairo",
    // 'Africa/Harare'        => "(GMT+02:00) Harare",
    // 'Europe/Helsinki'      => "(GMT+02:00) Helsinki",
    // 'Europe/Istanbul'      => "(GMT+02:00) Istanbul",
    // 'Asia/Jerusalem'       => "(GMT+02:00) Jerusalem",
    // 'Europe/Kiev'          => "(GMT+02:00) Kyiv",
    // 'Europe/Minsk'         => "(GMT+02:00) Minsk",
    // 'Europe/Riga'          => "(GMT+02:00) Riga",
    // 'Europe/Sofia'         => "(GMT+02:00) Sofia",
    // 'Europe/Tallinn'       => "(GMT+02:00) Tallinn",
    // 'Europe/Vilnius'       => "(GMT+02:00) Vilnius",
    // 'Asia/Baghdad'         => "(GMT+03:00) Baghdad",
    // 'Asia/Kuwait'          => "(GMT+03:00) Kuwait",
    // 'Africa/Nairobi'       => "(GMT+03:00) Nairobi",
    // 'Asia/Riyadh'          => "(GMT+03:00) Riyadh",
    // 'Europe/Moscow'        => "(GMT+03:00) Moscow",
    // 'Asia/Tehran'          => "(GMT+03:30) Tehran",
    // 'Asia/Baku'            => "(GMT+04:00) Baku",
    // 'Europe/Volgograd'     => "(GMT+04:00) Volgograd",
    // 'Asia/Muscat'          => "(GMT+04:00) Muscat",
    // 'Asia/Tbilisi'         => "(GMT+04:00) Tbilisi",
    // 'Asia/Yerevan'         => "(GMT+04:00) Yerevan",
    // 'Asia/Kabul'           => "(GMT+04:30) Kabul",
    // 'Asia/Karachi'         => "(GMT+05:00) Karachi",
    // 'Asia/Tashkent'        => "(GMT+05:00) Tashkent",
    // 'Asia/Kolkata'         => "(GMT+05:30) Kolkata",
    // 'Asia/Kathmandu'       => "(GMT+05:45) Kathmandu",
    // 'Asia/Yekaterinburg'   => "(GMT+06:00) Ekaterinburg",
    // 'Asia/Almaty'          => "(GMT+06:00) Almaty",
    // 'Asia/Dhaka'           => "(GMT+06:00) Dhaka",
    // 'Asia/Novosibirsk'     => "(GMT+07:00) Novosibirsk",
    // 'Asia/Bangkok'         => "(GMT+07:00) Bangkok",
    // 'Asia/Jakarta'         => "(GMT+07:00) Jakarta",
    // 'Asia/Krasnoyarsk'     => "(GMT+08:00) Krasnoyarsk",
    // 'Asia/Chongqing'       => "(GMT+08:00) Chongqing",
    // 'Asia/Hong_Kong'       => "(GMT+08:00) Hong Kong",
    // 'Asia/Kuala_Lumpur'    => "(GMT+08:00) Kuala Lumpur",
    // 'Australia/Perth'      => "(GMT+08:00) Perth",
    // 'Asia/Singapore'       => "(GMT+08:00) Singapore",
    // 'Asia/Taipei'          => "(GMT+08:00) Taipei",
    // 'Asia/Ulaanbaatar'     => "(GMT+08:00) Ulaan Bataar",
    // 'Asia/Urumqi'          => "(GMT+08:00) Urumqi",
    // 'Asia/Irkutsk'         => "(GMT+09:00) Irkutsk",
    // 'Asia/Seoul'           => "(GMT+09:00) Seoul",
    // 'Asia/Tokyo'           => "(GMT+09:00) Tokyo",
    // 'Australia/Adelaide'   => "(GMT+09:30) Adelaide",
    // 'Australia/Darwin'     => "(GMT+09:30) Darwin",
    // 'Asia/Yakutsk'         => "(GMT+10:00) Yakutsk",
    // 'Australia/Brisbane'   => "(GMT+10:00) Brisbane",
    // 'Australia/Canberra'   => "(GMT+10:00) Canberra",
    // 'Pacific/Guam'         => "(GMT+10:00) Guam",
    // 'Australia/Hobart'     => "(GMT+10:00) Hobart",
    // 'Australia/Melbourne'  => "(GMT+10:00) Melbourne",
    // 'Pacific/Port_Moresby' => "(GMT+10:00) Port Moresby",
    // 'Australia/Sydney'     => "(GMT+10:00) Sydney",
    // 'Asia/Vladivostok'     => "(GMT+11:00) Vladivostok",
    // 'Asia/Magadan'         => "(GMT+12:00) Magadan",
    // 'Pacific/Auckland'     => "(GMT+12:00) Auckland",
    // 'Pacific/Fiji'         => "(GMT+12:00) Fiji",
