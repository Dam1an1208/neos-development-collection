<?php
declare(ENCODING="utf-8");

/*                                                                        *
 * Routes configuration for the TYPO3 package                             *
 *                                                                        *
 *                                                                        */

$c->fallback
	->setUrlPattern('[dummy]')
	->setControllerComponentNamePattern('F3::@package::Frontend::Controller::@controller')
	->setDefaults(
		array(
			'dummy' => 'foo',
			'@package' => 'TYPO3',
			'@controller' => 'DefaultController',
			'@action' => 'default',
		)
	);

$c->TYPO3Route1
	->setUrlPattern('[page].[@format]')
	->setControllerComponentNamePattern('F3::@package::Frontend::Controller::@controller')
	->setDefaults(
		array(
			'@package' => 'TYPO3',
			'@controller' => 'DefaultController',
			'@action' => 'default',
			'page' => 'index',
			'@format' => 'html'
		)
	);

$c->TYPO3Route2
	->setUrlPattern('typo3/[section]/[module]')
	->setControllerComponentNamePattern('F3::@package::Backend::Controller::@controller')
	->setDefaults(
		array(
			'@package' => 'TYPO3',
			'@controller' => 'DefaultController',
			'@action' => 'default',
			'section' => 'System',
			'module' => 'Welcome'
		)
	);


/*************************************************************************
 * Routes definitions for the services
 */


$c->TYPO3Route_ServiceWithControllerOnly
	->setUrlPattern('typo3/service/v1/[@controller]')
	->setControllerComponentNamePattern('F3::@package::Service::Controller::@controller')
	->setDefaults(
		array(
			'@package' => 'TYPO3',
			'@format' => 'json'
		)
	);

$c->TYPO3Route_ServiceWithControllerAndFormat
	->setUrlPattern('typo3/service/v1/[@controller].[@format]')
	->setControllerComponentNamePattern('F3::@package::Service::Controller::@controller')
	->setDefaults(
		array(
			'@package' => 'TYPO3'
		)
	);

$c->TYPO3Route_ServiceWithControllerIdentifierAction
	->setUrlPattern('typo3/service/v1/[@controller]/[identifier]')
	->setControllerComponentNamePattern('F3::@package::Service::Controller::@controller')
	->setDefaults(
		array(
			'@package' => 'TYPO3',
			'@format' => 'json'
		)
	);

$c->TYPO3Route_ServiceWithControllerIdentifierActionAndFormat
	->setUrlPattern('typo3/service/v1/[@controller]/[identifier].[@format]')
	->setControllerComponentNamePattern('F3::@package::Service::Controller::@controller')
	->setDefaults(
		array(
			'@package' => 'TYPO3',
		)
	);

/*************************************************************************
 * Other routes (for experimentation)
 */

$c->TYPO3Route5
	->setUrlPattern('typo3/setup')
	->setControllerComponentNamePattern('F3::TYPO3::Backend::Controller::DefaultController')
	->setDefaults(
		array(
			'@action' => 'setup',
		)
	);

$c->TYPO3Route6
	->setUrlPattern('typo3/test/[action]')
	->setControllerComponentNamePattern('F3::@package::Backend::Controller::@controller')
	->setDefaults(
		array(
			'@package' => 'TYPO3',
			'@controller' => 'ServiceTest',
			'@action' => 'default',
		)
	);
?>