<?php

namespace Kyte\Session;

class SessionManager
{
	private $session;
	private $user;
	private $username_field;
	private $password_field;
	public $hasSession;

	public function __construct($session_model, $account_model, $username_field = 'email', $password_field = 'password', $multilogon = false, $timeout = 3600) {
		$this->session = new \Kyte\Core\ModelObject($session_model);
		$this->user = new \Kyte\Core\ModelObject($account_model);
		$this->username_field = $username_field;
		$this->password_field = $password_field;
		$this->timeout = $timeout;
		$this->multilogon = $multilogon;
		$this->hasSession = false;
	}

	protected function generateTxToken($time, $exp_time, $string) {
		return hash_hmac('sha256', $string.'-'.$time, $exp_time);
	}

	protected function generateSessionToken($string) {
		$bytes = random_bytes(5);
		return hash_hmac('sha256', $string, bin2hex($bytes));
	}

	public function create($username, $password, $conditions = null)
	{
		if (isset($username, $password)) {

			// verify user
			if (!$this->user->retrieve($this->username_field, $username, $conditions)) {
				$this->hasSession = false;
				throw new \Kyte\Exception\SessionException("Invalid username or password.");
			}

			if (!password_verify($password, $this->user->{$this->password_field})) {
				$this->hasSession = false;
				throw new \Kyte\Exception\SessionException("Invalid username or password.");
			}

			// delete existing session
			if (!$this->multilogon && $this->session->retrieve('uid', $this->user->id)) {
				$this->hasSession = false;
				$this->session->delete();
			}

			$time = time();
			$exp_time = $time+$this->timeout;
			// create new session
			$res = $this->session->create([
				'uid' => $this->user->id,
				'exp_date' => $exp_time,
				'sessionToken' => $this->generateSessionToken($this->user->{$this->username_field}),
				'txToken' => $this->generateTxToken($time, $exp_time, $this->user->{$this->username_field}),
			]);
			if (!$res) {
				$this->hasSession = false;
				throw new \Kyte\Exception\SessionException("Unable to create session.");
			}

			$this->hasSession = true;

			// return params for new session after successful creation
			return $this->session->getAllParams();
		} else throw new \Kyte\Exception\SessionException("Session credentials was not specified.");
		
	}

	public function validate($sessionToken)
	{
		// get current time
		$time = time();

		// check if session token exists and retrieve session object
		if (!$this->session->retrieve('sessionToken', $sessionToken)) {
			$this->hasSession = false;
			throw new \Kyte\Exception\SessionException("No valid session.");
		}
		
		// check if use is still active
		if (!$this->user->retrieve('id', $this->session->uid)) {
			$this->hasSession = false;
			throw new \Kyte\Exception\SessionException("Invalid session.");
		}

		// check for expriation
		if ($time > $this->session->exp_date) {
			$this->hasSession = false;
			throw new \Kyte\Exception\SessionException("Session expired.");
		}
		
		// create new expiration
		$exp_time = $time+$this->timeout;
		
		// update session with new expiration
		$this->session->save([
			'exp_date' => $exp_time,
		]);

		$this->hasSession = true;

		// return session variable
		return $this->session->getAllParams();
	}

	public function destroy() {
		$this->hasSession = false;

		if (!$this->session) {
			throw new \Kyte\Exception\SessionException("No valid session.");
		}
		$this->session->delete();
		return true;
	}
}
