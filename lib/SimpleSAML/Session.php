<?php

/**
 * The Session class holds information about a user session, and everything attached to it.
 *
 * The session will have a duration, and validity, and also cache information about the different
 * federation protocols, as Shibboleth and SAML 2.0. On the IdP side the Session class holds 
 * information about all the currently logged in SPs. This is used when the user initiate a 
 * Single-Log-Out.
 *
 * @author Andreas �kre Solberg, UNINETT AS. <andreas.solberg@uninett.no>
 * @package simpleSAMLphp
 * @version $Id$
 */
class SimpleSAML_Session {

	const STATE_ONLINE = 1;
	const STATE_LOGOUTINPROGRESS = 2;
	const STATE_LOGGEDOUT = 3;

	/**
	 * This is a timeout value for setData, which indicates that the data should be deleted
	 * on logout.
	 */
	const DATA_TIMEOUT_LOGOUT = 'logoutTimeout';


	/**
	 * This variable holds the instance of the session - Singleton approach.
	 */
	private static $instance = null;
	
	/**
	 * The track id is a new random unique identifier that is generate for each session.
	 * This is used in the debug logs and error messages to easily track more information
	 * about what went wrong.
	 */
	private $trackid = 0;
	
	private $idp = null;
	
	private $authenticated = null;
	private $attributes = null;
	
	private $sessionindex = null;
	private $nameid = null;
	
	private $sp_at_idpsessions = array();
	
	private $authority = null;
	
	// Session duration parameters
	private $sessionstarted = null;
	private $sessionduration = null;
	
	// Track whether the session object is modified or not.
	private $dirty = false;
		

	/**
	 * This is an array of registered logout handlers.
	 * All registered logout handlers will be called on logout.
	 */
	private $logout_handlers = array();


	/**
	 * This is an array of objects which will autoexpire after a set time. It is used
	 * where one needs to store some information - for example a logout request, but doesn't
	 * want it to be stored forever.
	 *
	 * The data store contains three levels of nested associative arrays. The first is the data type, the
	 * second is the identifier, and the third contains the expire time of the data and the data itself.
	 */
	private $dataStore = null;


	/**
	 * Current NameIDs for sessions.
	 *
	 * Stored as a two-level associative array: $sessionNameId[<entityType>][<entityId>]
	 */
	private $sessionNameId;


	/**
	 * Logout state when authenticated with authentication sources.
	 */
	private $logoutState;


	/**
	 * private constructor restricts instantiaton to getInstance()
	 */
	private function __construct() {
		
		$configuration = SimpleSAML_Configuration::getInstance();
		$this->sessionduration = $configuration->getInteger('session.duration', 8*60*60);
		
		$this->trackid = SimpleSAML_Utilities::generateTrackID();

		$this->dirty = TRUE;
		$this->addShutdownFunction();
	}


	/**
	 * This function is called after this class has been deserialized.
	 */
	public function __wakeup() {
		$this->addShutdownFunction();
	}
	
	
	/**
	 * Retrieves the current session. Will create a new session if there isn't a session.
	 *
	 * @return The current session.
	 */
	public static function getInstance() {

		/* Check if we already have initialized the session. */
		if (isset(self::$instance)) {
			return self::$instance;
		}


		/* Check if we have stored a session stored with the session
		 * handler.
		 */
		self::$instance = self::loadSession();
		if(self::$instance !== NULL) {
			return self::$instance;
		}


		/* Create a new session. */
		self::$instance = new SimpleSAML_Session();

		/* Save the new session with the session handler. */
		$sh = SimpleSAML_SessionHandler::getSessionHandler();
		$sh->set('SimpleSAMLphp_SESSION', self::$instance);

		return self::$instance;
	}


	/**
	 * Initializes a session with the specified authentication state.
	 *
	 * @param $authenticated  TRUE if this session is authenticated, FALSE if not.
	 * @param $authority      The authority which authenticated the session.
	 * @deprecated  Replace with getInstance() and doLogin(...) / doLogout().
	 */
	public static function init($authenticated = false, $authority = null) {
		$session = self::getInstance(TRUE);
		$session->clean();
		$session->setAuthenticated($authenticated, $authority);
	}
	
	
	
	
	
	/**
	 * Get a unique ID that will be permanent for this session.
	 * Used for debugging and tracing log files related to a session.
	 */
	public function getTrackID() {
		return $this->trackid;
	}
	
	/**
	 * Who authorized this session. could be in example saml2, shib13, login,login-admin etc.
	 */
	public function getAuthority() {
		return $this->authority;
	}
	
	
	
	// *** SP list to be used with SAML 2.0 SLO ***
	// *** *** *** *** *** *** *** *** *** *** ***
	
	public function add_sp_session($entityid) {
		SimpleSAML_Logger::debug('Library - Session: Adding SP session: ' . $entityid);
		$this->dirty = TRUE;
		$this->sp_at_idpsessions[$entityid] = self::STATE_ONLINE;
	}
	
	public function get_next_sp_logout() {
		
		if (!$this->sp_at_idpsessions) return null;

		$this->dirty = TRUE;
		
		foreach ($this->sp_at_idpsessions AS $entityid => $sp) {
			if ($sp == self::STATE_ONLINE) {
				$this->sp_at_idpsessions[$entityid] = self::STATE_LOGOUTINPROGRESS;
				return $entityid;
			}
		}
		return null;
	}
	
	public function get_sp_list($state = self::STATE_ONLINE) {
		
		$list = array();
		if (!$this->sp_at_idpsessions) return $list;
		
		foreach ($this->sp_at_idpsessions AS $entityid => $sp) {
			if ($sp == $state) {
				$list[] = $entityid;
			}
		}
		return $list;
	}
	
	public function sp_logout_completed() {

		if (!$this->sp_at_idpsessions) return TRUE;
		
		foreach ($this->sp_at_idpsessions AS $entityid => $sp) {
			if ($sp != self::STATE_LOGGEDOUT) return FALSE;
		}
		return TRUE;
	}
	
	
	public function set_sp_logout_completed($entityid) {
		SimpleSAML_Logger::debug('Library - Session: Setting SP state completed for : ' . $entityid);
		$this->dirty = true;
		$this->sp_at_idpsessions[$entityid] = self::STATE_LOGGEDOUT;
	}
	
	public function dump_sp_sessions() {
		foreach ($this->sp_at_idpsessions AS $entityid => $sp) {
			SimpleSAML_Logger::debug('Dump sp sessions: ' . $entityid . ' status: ' . $sp);
		}
	}
	// *** --- ***


	
	
	/**
	 * This method retrieves from session a cache of a specific Authentication Request
	 * The complete request is not stored, instead the values that will be needed later
	 * are stored in an assoc array.
	 *
	 * @param $protocol 		saml2 or shib13
	 * @param $requestid 		The request id used as a key to lookup the cache.
	 *
	 * @return Returns an assoc array of cached variables associated with the
	 * authentication request.
	 */
	public function getAuthnRequest($protocol, $requestid) {


		SimpleSAML_Logger::debug('Library - Session: Get authnrequest from cache ' . $protocol . ' time:' . time() . '  id: '. $requestid );

		$type = 'AuthnRequest-' . $protocol;
		$authnRequest = $this->getData($type, $requestid);

		if($authnRequest === NULL) {
			/*
			 * Could not find requested ID. Throw an error. Could be that it is never set, or that it is deleted due to age.
			 */
			throw new Exception('Could not find cached version of authentication request with ID ' . $requestid . ' (' . $protocol . ')');
		}

		return $authnRequest;
	}
	
	/**
	 * This method sets a cached assoc array to the authentication request cache storage.
	 *
	 * @param $protocol 		saml2 or shib13
	 * @param $requestid 		The request id used as a key to lookup the cache.
	 * @param $cache			The assoc array that will be stored.
	 */
	public function setAuthnRequest($protocol, $requestid, array $cache) {
	
		SimpleSAML_Logger::debug('Library - Session: Set authnrequest ' . $protocol . ' time:' . time() . ' size:' . count($cache) . '  id: '. $requestid );

		$type = 'AuthnRequest-' . $protocol;
		$this->setData($type, $requestid, $cache);
	}
	



	public function setIdP($idp) {
	
		SimpleSAML_Logger::debug('Library - Session: Set IdP to : ' . $idp);
		$this->dirty = true;
		$this->idp = $idp;
	}
	public function getIdP() {
		return $this->idp;
	}
	

	public function setSessionIndex($sessionindex) {
		SimpleSAML_Logger::debug('Library - Session: Set sessionindex: ' . $sessionindex);
		$this->dirty = true;
		$this->sessionindex = $sessionindex;
	}
	public function getSessionIndex() {
		if($this->sessionindex === NULL) {
			$this->sessionindex = SimpleSAML_Utilities::generateID();
		}
		return $this->sessionindex;
	}
	public function setNameID($nameid) {
		SimpleSAML_Logger::debug('Library - Session: Set nameID: ');
		$this->dirty = true;
		$this->nameid = $nameid;
	}
	public function getNameID() {
		if (array_key_exists('value', $this->nameid)) {
			/*
			 * This session was saved by an old version of simpleSAMLphp.
			 * Convert to the new NameId format.
			 *
			 * TODO: Remove this conversion once every session uses the new format.
			 */
			$this->nameid['Value'] = $this->nameid['value'];
			unset($this->nameid['value']);

			$this->dirty = TRUE;
		}

		return $this->nameid;
	}


	/**
	 * Set the NameID of the users session to the specified entity.
	 *
	 * @param string $entityType  The type of the entity (saml20-sp-remote, shib13-sp-remote, ...).
	 * @param string $entityId  The entity id.
	 * @param array $nameId  The name identifier.
	 */
	public function setSessionNameId($entityType, $entityId, $nameId) {
		assert('is_string($entityType)');
		assert('is_string($entityId)');
		assert('is_array($nameId)');

		if(!is_array($this->sessionNameId)) {
			$this->sessionNameId = array();
		}

		if(!array_key_exists($entityType, $this->sessionNameId)) {
			$this->sessionNameId[$entityType] = array();
		}

		$this->sessionNameId[$entityType][$entityId] = $nameId;
	}


	/**
	 * Get the NameID of the users session to the specified entity.
	 *
	 * @param string $entityType  The type of the entity (saml20-sp-remote, shib13-sp-remote, ...).
	 * @param string $entityId  The entity id.
	 * @return array  The name identifier, or NULL if no name identifier is associated with this session.
	 */
	public function getSessionNameId($entityType, $entityId) {
		assert('is_string($entityType)');
		assert('is_string($entityId)');

		if(!is_array($this->sessionNameId)) {
			return NULL;
		}

		if(!array_key_exists($entityType, $this->sessionNameId)) {
			return NULL;
		}

		if(!array_key_exists($entityId, $this->sessionNameId[$entityType])) {
			return NULL;
		}

		$nameId = $this->sessionNameId[$entityType][$entityId];
		if (array_key_exists('value', $nameId)) {
			/*
			 * This session was saved by an old version of simpleSAMLphp.
			 * Convert to the new NameId format.
			 *
			 * TODO: Remove this conversion once every session should use the new format.
			 */
			$nameId['Value'] = $nameId['value'];
			unset($nameId['value']);
		}

		return $nameId;
	}


	/**
	 * Marks the user as logged in with the specified authority.
	 *
	 * If the user already has logged in, the user will be logged out first.
	 *
	 * @param @authority  The authority the user logged in with.
	 */
	public function doLogin($authority) {
		assert('is_string($authority)');

		SimpleSAML_Logger::debug('Session: doLogin("' . $authority . '")');

		$this->dirty = TRUE;

		if($this->authenticated) {
			/* We are already logged in. Log the user out first. */
			$this->doLogout();
		}

		$this->authenticated = TRUE;
		$this->authority = $authority;

		$this->sessionstarted = time();

		/* Clear NeedAuthentication flags. This flag is used to implement ForceAuthn. */
		$this->clearNeedAuthFlag();
	}


	/**
	 * Marks the user as logged out.
	 *
	 * This function will call any registered logout handlers before marking the user as logged out.
	 */
	public function doLogout() {

		SimpleSAML_Logger::debug('Session: doLogout()');

		$this->dirty = TRUE;

		$this->callLogoutHandlers();

		$this->authenticated = FALSE;
		$this->authority = NULL;
		$this->attributes = NULL;
		$this->logoutState = NULL;
		$this->idp = NULL;

		/* Delete data which expires on logout. */
		$this->expireDataLogout();
	}


	/**
	 * Sets the current authentication state of the user.
	 *
	 * @param $auth       The current authentication state of the user.
	 * @param $authority  The authority (if the user is authenticated).
	 * @deprecated  Replaced with doLogin(...) and doLogout().
	 */
	public function setAuthenticated($auth, $authority = null) {
		
		SimpleSAML_Logger::debug('Library - Session: Set authenticated ' . ($auth ? 'yes': 'no'). ' authority:' . 
			(isset($authority) ? $authority : 'null'));

		if ($auth) {	
			if(!is_string($authority)) {
				$authority = 'null';
			}
			$this->doLogin($authority);
		} else {
			$this->doLogout();
		}
	}
	
	public function setSessionDuration($duration) {
		SimpleSAML_Logger::debug('Library - Session: Set session duration ' . $duration);
		$this->dirty = true;
		$this->sessionduration = $duration;
	}
	
	
	/*
	 * Is the session representing an authenticated user, and is the session still alive.
	 * This function will return false after the user has timed out.
	 *
	 * @param string $authority  The authentication source that the user should be authenticated with.
	 * @return TRUE if the user has a valid session, FALSE if not.
	 */
	public function isValid($authority) {
		assert('is_string($authority)');

		SimpleSAML_Logger::debug('Library - Session: Check if session is valid.' .
			' checkauthority:' . $authority .
			' thisauthority:' . (isset($this->authority) ? $this->authority : 'null') .
			' isauthenticated:' . ($this->isAuthenticated() ? 'yes' : 'no') . 
			' remainingtime:' . $this->remainingTime());
			
		if (!$this->isAuthenticated()) return false;

		if ($authority !== $this->authority) {
			return FALSE;
		}

		return $this->remainingTime() > 0;
	}
	
	/*
	 * If the user is authenticated, how much time is left of the session.
	 */
	public function remainingTime() {
		return $this->sessionduration - (time() - $this->sessionstarted);
	}

	/* 
	 * Is the user authenticated. This function does not check the session duration.
	 */
	public function isAuthenticated() {
		return $this->authenticated;
	}
	
	
	// *** Attributes ***
	
	public function getAttributes() {
		return $this->attributes;
	}

	public function getAttribute($name) {
		return $this->attributes[$name];
	}

	public function setAttributes($attributes) {
		$this->dirty = true;
		$this->attributes = $attributes;
	}
	
	public function setAttribute($name, $value) {
		$this->dirty = true;
		$this->attributes[$name] = $value;
	}
	
	/**
	 * Clean the session object.
	 */
	public function clean($cleancache = false) {
	
		SimpleSAML_Logger::debug('Library - Session: Cleaning Session. Clean cache: ' . ($cleancache ? 'yes' : 'no') );
	
		if ($cleancache) {
			$this->dataStore = null;
			$this->idp = null;
		}
		
		$this->authority = null;
	
		$this->authenticated = null;
		$this->attributes = null;
	
		$this->sessionindex = null;
		$this->nameid = null;
	
		$this->sp_at_idpsessions = array();	
		$this->dirty = TRUE;
	}
	 
	/**
	 * Calculates the size of the session object after serialization
	 *
	 * @return The size of the session measured in bytes.
	 */
	public function getSize() {
		$s = serialize($this);
		return strlen($s);
	}


	/**
	 * This function registers a logout handler.
	 *
	 * @param $classname  The class which contains the logout handler.
	 * @param $functionname  The logout handler function.
	 */
	public function registerLogoutHandler($classname, $functionname) {

		$logout_handler = array($classname, $functionname);

		if(!is_callable($logout_handler)) {
			throw new Exception('Logout handler is not a vaild function: ' . $classname . '::' .
				$functionname);
		}


		$this->logout_handlers[] = $logout_handler;
		$this->dirty = TRUE;
	}


	/**
	 * This function calls all registered logout handlers.
	 */
	private function callLogoutHandlers() {
		foreach($this->logout_handlers as $handler) {

			/* Verify that the logout handler is a valid function. */
			if(!is_callable($handler)) {
				$classname = $handler[0];
				$functionname = $handler[1];

				throw new Exception('Logout handler is not a vaild function: ' . $classname . '::' .
					$functionname);
			}

			/* Call the logout handler. */
			call_user_func($handler);

		}

		/* We require the logout handlers to register themselves again if they want to be called later. */
		$this->logout_handlers = array();
	}


	/**
	 * This function iterates over all current authentication requests, and removes any 'NeedAuthentication' flags
	 * from them.
	 */
	private function clearNeedAuthFlag() {

		foreach(array('AuthnRequest-saml2', 'AuthnRequest-shib13') as $type) {
			foreach($this->getDataOfType($type) as $id => $request) {

				if(!array_key_exists('NeedAuthentication', $request)) {
					continue;
				}

				if($request['NeedAuthentication'] === FALSE) {
					continue;
				}

				$request['NeedAuthentication'] = FALSE;
				$this->setData($type, $id, $request);
			}
		}
	}


	/**
	 * This function removes expired data from the data store.
	 *
	 * Note that this function doesn't mark the session object as dirty. This means that
	 * if the only change to the session object is that some data has expired, it will not be
	 * written back to the session store.
	 */
	private function expireData() {

		if(!is_array($this->dataStore)) {
			return;
		}

		$ct = time();

		foreach($this->dataStore as &$typedData) {
			foreach($typedData as $id => $info) {
				if ($info['expires'] === self::DATA_TIMEOUT_LOGOUT) {
					/* This data only expires on logout. */
					continue;
				}

				if($ct > $info['expires']) {
					unset($typedData[$id]);
				}
			}
		}
	}


	/**
	 * This function deletes data which should be deleted on logout from the data store.
	 */
	private function expireDataLogout() {

		if(!is_array($this->dataStore)) {
			return;
		}

		$this->dirty = TRUE;

		foreach ($this->dataStore as &$typedData) {
			foreach ($typedData as $id => $info) {
				if ($info['expires'] === self::DATA_TIMEOUT_LOGOUT) {
					unset($typedData[$id]);
				}
			}
		}
	}


	/**
	 * Delete data from the data store.
	 *
	 * This function immediately deletes the data with the given type and id from the data store.
	 *
	 * @param string $type  The type of the data.
	 * @param string $id  The identifier of the data.
	 */
	public function deleteData($type, $id) {
		assert('is_string($type)');
		assert('is_string($id)');

		if (!is_array($this->dataStore)) {
			return;
		}

		if(!array_key_exists($type, $this->dataStore)) {
			return;
		}

		unset($this->dataStore[$type][$id]);
		$this->dirty = TRUE;
	}


	/**
	 * This function stores data in the data store.
	 *
	 * The timeout value can be SimpleSAML_Session::DATA_TIMEOUT_LOGOUT, which indicates
	 * that the data should be deleted on logout (and not before).
	 *
	 * @param $type     The type of the data. This is checked when retrieving data from the store.
	 * @param $id       The identifier of the data.
	 * @param $data     The data.
	 * @param $timeout  The number of seconds this data should be stored after its last access.
	 *                  This parameter is optional. The default value is set in 'session.datastore.timeout',
	 *                  and the default is 4 hours.
	 */
	public function setData($type, $id, $data, $timeout = NULL) {
		assert(is_string($type));
		assert(is_string($id));
		assert('is_int($timeout) || is_null($timeout) || $timeout === self::DATA_TIMEOUT_LOGOUT');

		/* Clean out old data. */
		$this->expireData();

		if($timeout === NULL) {
			/* Use the default timeout. */

			$configuration = SimpleSAML_Configuration::getInstance();

			$timeout = $configuration->getInteger('session.datastore.timeout', NULL);
			if($timeout !== NULL) {
				if ($timeout <= 0) {
					throw new Exception('The value of the session.datastore.timeout' .
						' configuration option should be a positive integer.');
				}
			} else {
				/* For backwards compatibility. */
				$timeout = $configuration->getInteger('session.requestcache', 4*(60*60));
				if ($timeout <= 0) {
					throw new Exception('The value of the session.requestcache' .
						' configuration option should be a positive integer.');
				}
			}
		}

		if ($timeout === self::DATA_TIMEOUT_LOGOUT) {
			$expires = self::DATA_TIMEOUT_LOGOUT;
		} else {
			$expires = time() + $timeout;
		}

		$dataInfo = array(
			'expires' => $expires,
			'timeout' => $timeout,
			'data' => $data
			);

		if(!is_array($this->dataStore)) {
			$this->dataStore = array();
		}

		if(!array_key_exists($type, $this->dataStore)) {
			$this->dataStore[$type] = array();
		}

		$this->dataStore[$type][$id] = $dataInfo;

		$this->dirty = TRUE;
	}


	/**
	 * This function retrieves data from the data store.
	 *
	 * Note that this will not change when the data stored in the data store will expire. If that is required,
	 * the data should be written back with setData.
	 *
	 * @param $type  The type of the data. This must match the type used when adding the data.
	 * @param $id    The identifier of the data. Can be NULL, in which case NULL will be returned.
	 * @return The data of the given type with the given id or NULL if the data doesn't exist in the data store.
	 */
	public function getData($type, $id) {
		assert('is_string($type)');
		assert('$id === NULL || is_string($id)');

		if($id === NULL) {
			return NULL;
		}

		$this->expireData();

		if(!is_array($this->dataStore)) {
			return NULL;
		}

		if(!array_key_exists($type, $this->dataStore)) {
			return NULL;
		}

		if(!array_key_exists($id, $this->dataStore[$type])) {
			return NULL;
		}

		return $this->dataStore[$type][$id]['data'];
	}


	/**
	 * This function retrieves all data of the specified type from the data store.
	 *
	 * The data will be returned as an associative array with the id of the data as the key, and the data
	 * as the value of each key. The value will be stored as a copy of the original data. setData must be
	 * used to update the data.
	 *
	 * An empty array will be returned if no data of the given type is found.
	 *
	 * @param $type  The type of the data.
	 * @return An associative array with all data of the given type.
	 */
	public function getDataOfType($type) {
		assert('is_string($type)');

		if(!is_array($this->dataStore)) {
			return array();
		}

		if(!array_key_exists($type, $this->dataStore)) {
			return array();
		}

		$ret = array();
		foreach($this->dataStore[$type] as $id => $info) {
			$ret[$id] = $info['data'];
		}

		return $ret;
	}


	/**
	 * Load a session from the session handler.
	 *
	 * @return The session which is stored in the session handler, or NULL if the session wasn't found.
	 */
	private static function loadSession() {

		$sh = SimpleSAML_SessionHandler::getSessionHandler();
		$sessionData = $sh->get('SimpleSAMLphp_SESSION');
		if($sessionData == NULL) {
			return NULL;
		}

		if(!is_string($sessionData)) {
			return NULL;
		}

		$sessionData = unserialize($sessionData);

		if(!($sessionData instanceof self)) {
			SimpleSAML_Logger::warning('Retrieved and deserialized session data was not a session.');
			return NULL;
		}

		return $sessionData;
	}


	/**
	 * Save the session to the session handler.
	 *
	 * This function will check the dirty-flag to check if the session has changed.
	 */
	public function saveSession() {

		if(!$this->dirty) {
			/* Session hasn't changed - don't bother saving it. */
			return;
		}

		$this->dirty = FALSE;
		$sessionData = serialize($this);

		$sh = SimpleSAML_SessionHandler::getSessionHandler();
		$sh->set('SimpleSAMLphp_SESSION', $sessionData);
	}


	/**
	 * Add a shutdown function for saving this session object on exit.
	 */
	private function addShutdownFunction() {
		register_shutdown_function(array($this, 'saveSession'));
	}


	/**
	 * Set the logout state for this session.
	 *
	 * @param array $state  The state array.
	 */
	public function setLogoutState($state) {
		assert('is_array($state)');

		$this->dirty = TRUE;
		$this->logoutState = $state;
	}


	/**
	 * Retrieve the logout state for this session.
	 *
	 * @return array  The logout state. If no logout state is set, an empty array will be returned.
	 */
	public function getLogoutState() {

		if ($this->logoutState === NULL) {
			return array();
		}

		return $this->logoutState;
	}

}

?>