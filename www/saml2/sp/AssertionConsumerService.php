<?php

require_once('../../_include.php');

/**
 * This SAML 2.0 endpoint is the endpoint at the SAML 2.0 SP that takes an Authentication Response
 * as HTTP-POST in, and parses and processes it before it redirects the use to the RelayState.
 *
 * @author Andreas Aakre Solberg, UNINETT AS. <andreas.solberg@uninett.no>
 * @package simpleSAMLphp
 * @version $Id$
 * @abstract
 */

$config = SimpleSAML_Configuration::getInstance();

/* Get the session object for the user. Create a new session if no session
 * exists for this user.
 */
$session = SimpleSAML_Session::getInstance();


/**
 * Finish login operation.
 *
 * This helper function finishes a login operation and redirects the user back to the page which
 * requested the login.
 *
 * @param array $authProcState  The state of the authentication process.
 */
function finishLogin($authProcState) {
	assert('is_array($authProcState)');
	assert('array_key_exists("Attributes", $authProcState)');
	assert('array_key_exists("core:saml20-sp:NameID", $authProcState)');
	assert('array_key_exists("core:saml20-sp:SessionIndex", $authProcState)');
	assert('array_key_exists("core:saml20-sp:TargetURL", $authProcState)');
	assert('array_key_exists("Source", $authProcState)');
	assert('array_key_exists("entityid", $authProcState["Source"])');

	global $session;

	/* Update the session information */
	$session->doLogin('saml2');
	$session->setAttributes($authProcState['Attributes']);
	$session->setNameID($authProcState['core:saml20-sp:NameID']);
	$session->setSessionIndex($authProcState['core:saml20-sp:SessionIndex']);
	$session->setIdP($authProcState['Source']['entityid']);

	SimpleSAML_Utilities::redirect($authProcState['core:saml20-sp:TargetURL']);
}

SimpleSAML_Logger::info('SAML2.0 - SP.AssertionConsumerService: Accessing SAML 2.0 SP endpoint AssertionConsumerService');

if (!$config->getBoolean('enable.saml20-sp', TRUE))
	SimpleSAML_Utilities::fatalError($session->getTrackID(), 'NOACCESS');

if (array_key_exists(SimpleSAML_Auth_ProcessingChain::AUTHPARAM, $_REQUEST)) {
	/* We have returned from the authentication processing filters. */

	$authProcId = $_REQUEST[SimpleSAML_Auth_ProcessingChain::AUTHPARAM];
	$authProcState = SimpleSAML_Auth_ProcessingChain::fetchProcessedState($authProcId);
	finishLogin($authProcState);
}


if (empty($_REQUEST['SAMLResponse']))
	SimpleSAML_Utilities::fatalError($session->getTrackID(), 'ACSPARAMS', $exception);

	
try {

	$b = SAML2_Binding::getCurrentBinding();
	$response = $b->receive();
	if (!($response instanceof SAML2_Response)) {
		throw new SimpleSAML_Error_BadRequest('Invalid message received to AssertionConsumerService endpoint.');
	}

	$idp = $response->getIssuer();
	if ($idp === NULL) {
		throw new Exception('Missing <saml:Issuer> in message delivered to AssertionConsumerService.');
	}

	$metadataHandler = SimpleSAML_Metadata_MetaDataStorageHandler::getMetadataHandler();
	$sp = $metadataHandler->getMetaDataCurrentEntityID();

	$idpMetadata = $metadataHandler->getMetaDataConfig($idp, 'saml20-idp-remote');
	$spMetadata = $metadataHandler->getMetaDataConfig($sp, 'saml20-sp-hosted');

	/* Fetch the request information if it exists, fall back to RelayState if not. */
	$requestId = $response->getInResponseTo();
	$info = $session->getData('SAML2:SP:SSO:Info', $requestId);
	if($info === NULL) {
		/* Fall back to RelayState. */
		$info = array();
		$info['RelayState'] = $response->getRelayState();
		if(empty($info['RelayState'])) {
			$info['RelayState'] = $spMetadata->getString('RelayState', NULL);
		}
		if(empty($info['RelayState'])) {
			/* RelayState missing. */
			SimpleSAML_Utilities::fatalError($session->getTrackID(), 'NORELAYSTATE');
		}
	}


	try {
		$assertion = sspmod_saml2_Message::processResponse($spMetadata, $idpMetadata, $response);
	} catch (sspmod_saml2_Error $e) {
		/* The status of the response wasn't "success". */

		$status = $response->getStatus();
		if(array_key_exists('OnError', $info)) {
			/* We have an error handler. Return the error to it. */
			SimpleSAML_Utilities::redirect($info['OnError'], array('StatusCode' => $status['Code']));
		}

		/* We don't have an error handler. Show an error page. */
		SimpleSAML_Utilities::fatalError($session->getTrackID(), 'RESPONSESTATUSNOSUCCESS', $e);
	}


	SimpleSAML_Logger::info('SAML2.0 - SP.AssertionConsumerService: Successful response from IdP');

	/*
	 * Attribute handling
	 */
	$attributes = $assertion->getAttributes();

	SimpleSAML_Logger::stats('saml20-sp-SSO ' . $metadataHandler->getMetaDataCurrentEntityID() . ' ' . $idp . ' NA');
	

	$nameId = $assertion->getNameId();

	/* Begin module attribute processing */

	$spMetadataArray = $spMetadata->toArray();
	$idpMetadataArray = $idpMetadata->toArray();

	$pc = new SimpleSAML_Auth_ProcessingChain($idpMetadataArray, $spMetadataArray, 'sp');

	$authProcState = array(
		'core:saml20-sp:NameID' => $nameId,
		'core:saml20-sp:SessionIndex' => $assertion->getSessionIndex(),
		'core:saml20-sp:TargetURL' => $info['RelayState'],
		'ReturnURL' => SimpleSAML_Utilities::selfURLNoQuery(),
		'Attributes' => $attributes,
		'Destination' => $spMetadataArray,
		'Source' => $idpMetadataArray,
	);

	$pc->processState($authProcState);
	/* Since this function returns, processing has completed and attributes have
	 * been updated.
	 */

	finishLogin($authProcState);

} catch(Exception $exception) {
	SimpleSAML_Utilities::fatalError($session->getTrackID(), 'PROCESSASSERTION', $exception);
}


?>