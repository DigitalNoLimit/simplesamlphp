<?php
$identity = $this->data['identity'];
$loggedInAs = $this->data['loggedInAs'];
$loginURL = $this->data['loginURL'];
$logoutURL = $this->data['logoutURL'];
$ownPage = $this->data['ownPage'];
$serverURL = $this->data['serverURL'];
$trustedSites = $this->data['trustedSites'];
$userId = $this->data['userId'];
$userIdURL = $this->data['userIdURL'];

if ($userId !== FALSE) {
	$title = $this->t('{openidProvider:op:title_user}', array('%USERID%' => htmlspecialchars($userId)));
} else {
	$title = $this->t('{openidProvider:op:title_no_user}');
}

$serverLink = '<link rel="openid.server" href="' . htmlspecialchars($serverURL) . '" />';
$delegateLink = '<link rel="openid.delegate" href="' . htmlspecialchars($userIdURL) . '" />';

$this->data['header'] = $title;
$this->data['head'] = $serverLink;
$this->includeAtTemplateBase('includes/header.php');

echo('<h2>' . $title . '</h2>');

if ($userId !== FALSE) {
	echo('<p>' . $this->t('{openidProvider:op:user_page_for}', array('%USERID%' => htmlspecialchars($userId))) . '</p>');
}

if ($loggedInAs === NULL) {
	echo('<p><a href="' . htmlspecialchars($loginURL) . '">' . $this->t('{openidProvider:op:login_view_own_page}') . '</a></p>');
} elseif (!$ownPage) {
	echo('<p><a href="' . htmlspecialchars($identity) . '">' . $this->t('{openidProvider:op:view_own_page}') . '</a></p>');
}

if ($ownPage) {


	echo('<h3>Using your OpenID</h3>');
	echo('<p>');
	echo($this->t('{openidProvider:op:your_identifier}') . '<br />');
	echo('<code>' . htmlspecialchars($userIdURL) . '</code>');
	echo('</p>');
	echo('<p>');
	echo($this->t('{openidProvider:op:howto_delegate}'));
	echo('<br />');
	echo('<pre>' . htmlspecialchars($serverLink) . "\n" . htmlspecialchars($delegateLink) . '</pre>');
	echo('</p>');

	echo('<h3>' . $this->t('{openidProvider:op:trustlist_trustedsites}') . '</h3>');
	if (count($trustedSites) > 0) {
		echo('<div class="form">');
		echo('<form method="post" action="?">');
		echo('<ul>');

		foreach ($trustedSites as $site) {
			echo '<li>';
			echo '<input type="submit" name="remove_' . bin2hex($site) .
				'" value="' . $this->t('{openidProvider:op:trustlist_remove}') . '" />';
			echo ' <code>' . htmlspecialchars($site) . '</code>';
			echo '</li>';
		}
		echo('</ul>');
		echo('</form>');
		echo('</div>');
	} else {
		echo('<p>' . $this->t('{openidProvider:op:trustlist_nosites}') . '</p>');
	}

	echo('<h3>' . $this->t('{openidProvider:op:logout_title}') . '</h3>');
	echo('<p><a href="' . htmlspecialchars($logoutURL) . '">' . $this->t('{openidProvider:op:logout}') . '</a></p>');
}

$this->includeAtTemplateBase('includes/footer.php');
?>