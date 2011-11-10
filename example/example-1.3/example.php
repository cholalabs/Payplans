<?php

/**
* @copyright	Copyright (C) 2009 - 2009 Ready Bytes Software Labs Pvt. Ltd. All rights reserved.
* @license	GNU/GPL, see LICENSE.php
* @package	Payplans
* @subpackage	Alert Pay Payment App
* @contact	payplans@readybytes.in
*/
// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

/**
 * Payplans Alert Pay Plugin
 * @author Gaurav
 */
class plgPayplansAlertpay extends XiPlugin
{
	public function onPayplansSystemStart()
	{
		//add discount app path to app loader
		$appPath = dirname(__FILE__).DS.'alertpay'.DS.'app';
		PayplansHelperApp::addAppsPath($appPath);

		return true;
	}
}
