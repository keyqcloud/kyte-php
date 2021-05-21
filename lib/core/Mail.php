<?php

namespace Kyte;

class Mail {
	private static $sendgridAPIKey;

	/*
	 * Sets API key for SendGrid
	 *
	 * @param string $dbName
	 */
	public static function setSendGridAPIKey($key)
	{
		self::$sendgridAPIKey = $key;
	}

	/*
	 * Send email via SendGrid
	 *
	 * @param array $to[email=>name]
	 * @param array $from[email=>name]
	 * @param string $subject
	 * @param string $body
	 */
	public static function email($to, $from, $subject, $body, $type = 'text/plain')
	{
		$sg = new \SendGrid(self::$sendgridAPIKey);

		$email = new \SendGrid\Mail\Mail();
		$email->setFrom($from['address'], $from['name']);
		$email->setSubject($subject);
		$email->addTo($to['address'], $to['name']);
		
		$email->addContent($type, $body);

		$response = $sg->send($email);
	}
}

?>
