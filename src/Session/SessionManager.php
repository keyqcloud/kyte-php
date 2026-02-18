<?php

namespace Kyte\Session;

/**
 * Class SessionManager
 *
 * Manages user sessions and provides functionality for session creation, validation, and destruction.
 * 
 * @package Kyte\Session;
 */
class SessionManager
{
	/**
     * @var \Kyte\Core\ModelObject The session model object.
     */
    private $session;

    /**
     * @var \Kyte\Core\ModelObject The user model object.
     */
    private $user;

    /**
     * @var string The field name for the username.
     */
    private $username_field;

    /**
     * @var string The field name for the password.
     */
    private $password_field;

	/**
     * @var string|null The optional application identifier.
     */
    private $appId = null;

    /**
     * @var int The session timeout duration in seconds.
     */
    private $timeout;

    /**
     * @var bool Indicates if multilogon is enabled.
     */
    private $multilogon;

    /**
     * @var bool Indicates if there is an active session.
     */
    public $hasSession;

	/**
     * SessionManager constructor.
     *
     * @param string $session_model   The session model name.
     * @param string $user_model      The user model name.
     * @param string $username_field  The field name for the username.
     * @param string $password_field  The field name for the password.
     * @param bool   $multilogon      Indicates if multilogon is enabled.
     * @param int    $timeout         The session timeout duration in seconds.
     */
	public function __construct($session_model, $user_model, $username_field = 'email', $password_field = 'password', $appId = null, $multilogon = false, $timeout = 3600) {
		$this->session = new \Kyte\Core\ModelObject($session_model);
		$this->user = new \Kyte\Core\ModelObject($user_model);
		$this->username_field = $username_field;
		$this->password_field = $password_field;
		$this->appId = $appId;
		$this->timeout = $timeout;
		$this->multilogon = $multilogon;
		$this->hasSession = false;
	}

	/**
	 * Generates a transaction token based on the given parameters.
	 *
	 * @param int    $time     The current time.
	 * @param int    $exp_time The expiration time.
	 * @param string $string   The string to generate the token from.
	 *
	 * @return string The generated transaction token.
	 */
	protected function generateTxToken($time, $exp_time, $string)
	{
		return hash_hmac('sha256', $string . '-' . $time, $exp_time);
	}

	/**
	 * Generates a session token based on the given string.
	 *
	 * @param string $string The string to generate the token from.
	 *
	 * @return string The generated session token.
	 */
	protected function generateSessionToken($string)
	{
		$bytes = random_bytes(5);
		return hash_hmac('sha256', $string, bin2hex($bytes));
	}


	/**
     * Creates a new session with the specified username and password.
     *
     * @param string $username   The username.
     * @param string $password   The password.
     * @param array  $conditions Additional conditions for user retrieval (optional).
     *
     * @return array The session parameters.
     * @throws \Kyte\Exception\SessionException If the username or password is invalid, or session creation fails.
     */
	public function create($username, $password, $conditions = null)
	{
		$remoteIP = $_SERVER['REMOTE_ADDR'];
		$forwardedIP = $_SERVER['HTTP_X_FORWARDED_FOR'];
		$userAgent = $_SERVER['HTTP_USER_AGENT'];

		if (isset($username, $password)) {

			// verify user
			if (!$this->user->retrieve($this->username_field, $username, $conditions)) {
				$this->hasSession = false;
				// Log failed login attempt
				try {
					\Kyte\Core\ActivityLogger::getInstance()->logAuth('LOGIN_FAIL', $username, false, 'Invalid username');
				} catch (\Exception $e) {}
				throw new \Kyte\Exception\SessionException("Invalid username or password.");
			}

			if (!password_verify($password, $this->user->{$this->password_field})) {
				$this->hasSession = false;
				// Log failed login attempt
				try {
					\Kyte\Core\ActivityLogger::getInstance()->logAuth('LOGIN_FAIL', $username, false, 'Invalid password');
				} catch (\Exception $e) {}
				throw new \Kyte\Exception\SessionException("Invalid username or password.");
			}

			$cond = $this->appId === null ? null : [['field' => 'appIdentifier', 'value' => $this->appId]];

			// delete existing session
			if (!$this->multilogon && $this->session->retrieve('uid', $this->user->id, $cond)) {
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
				'appIdentifier' => $this->appId,
				'remoteIP' => $remoteIP,
				'forwardedIP' => $forwardedIP,
				'userAgent' => $userAgent,
			]);
			if (!$res) {
				$this->hasSession = false;
				throw new \Kyte\Exception\SessionException("Unable to create session.");
			}

			$this->hasSession = true;

			// Log successful login
			try {
				\Kyte\Core\ActivityLogger::getInstance()->logAuth('LOGIN', $username, true);
			} catch (\Exception $e) {}

			// return params for new session after successful creation
			return $this->session->getAllParams();
		} else {
			throw new \Kyte\Exception\SessionException("Session credentials was not specified.");
		}
		
	}

	/**
     * Validates a session with the given session token.
     *
     * @param string $sessionToken The session token.
     *
     * @return array The session parameters.
     * @throws \Kyte\Exception\SessionException If the session is invalid or expired.
     */
	public function validate($sessionToken)
	{
		// get current time
		$time = time();

		$remoteIP = $_SERVER['REMOTE_ADDR'];
		$forwardedIP = $_SERVER['HTTP_X_FORWARDED_FOR'];
		$userAgent = $_SERVER['HTTP_USER_AGENT'];

		// check if session token exists and retrieve session object
		$cond = $this->appId === null ? null : [['field' => 'appIdentifier', 'value' => $this->appId]];
		if (!$this->session->retrieve('sessionToken', $sessionToken, $cond)) {
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
		return ['session' => $this->session, 'user' => $this->user];
	}

	/**
     * Destroys the current session.
     *
     * @return bool True if the session was destroyed successfully, false otherwise.
     * @throws \Kyte\Exception\SessionException If there is no valid session.
     */
	public function destroy() {
		$this->hasSession = false;

		if (!$this->session) {
			throw new \Kyte\Exception\SessionException("No valid session.");
		}

		// Log logout event
		try {
			$email = isset($this->user->email) ? $this->user->email : (isset($this->user->{$this->username_field}) ? $this->user->{$this->username_field} : null);
			\Kyte\Core\ActivityLogger::getInstance()->logAuth('LOGOUT', $email, true);
		} catch (\Exception $e) {}

		$this->session->delete();
		return true;
	}
}
