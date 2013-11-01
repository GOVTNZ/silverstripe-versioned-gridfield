silverstripe-versioned-gridfield / Versioned Model Admin
===============================

## Requirements

 * SilverStripe 3.1 or newer

## Introduction

This module provides a GridFieldDetailForm, with the associated actions required for managing versioned objects,
e.g. SiteTree descendants and DataObjects with the Versioned extension.

This comes in handy especially when using a ModelAdmin to manage parts of the SiteTree.

It hooks into ModelAdmin via updateEditForm and inserts the VersionedGridFieldDetailForm automatically.

## Acknowledgements

This started as a fork of [Tim Klein](https://github.com/icecaster)'s [module](https://github.com/icecaster/silverstripe-versioned-gridfield).

It also makes use of a [pull request](https://github.com/icecaster/silverstripe-versioned-gridfield/pull/5) raised on Tim's repo by [clyonsEIS](https://github.com/clyonsEIS).

## Usage

### Simple

Example of enable versioning on a DataObject that will be maintained via ModelAdmin.

Service DataObject:
```php
<?php

class Service extends DataObject {

	private static $db = array(
		'Name' => 'Text',
		'Type' => 'Text'
	);

	private static $versioning = array(
		"Stage",
		"Live"
	);

	private static $extensions = array(
		"Versioned('Stage', 'Live')"
	);

}
```

ModelAdmin code:
```php
<?php

class ServiceAdmin extends ModelAdmin {

	private static $managed_models = array(
		'Service'
	);

	private static $url_segment = 'services';
	private static $menu_title = 'Services';

}
```

### Complex

To enable versioning on a DataObject that is maintained via a GridField on another DataObject
(assume the same ModelAdmin code as above is in use in this example).

Service DataObject:
```php
<?php

class Service extends DataObject {

	private static $db = array(
		'Name' => 'Text',
		'Type' => 'Text'
	);

	private static $has_many = array(
		'Providers' => 'Provider'
	);

	private static $versioning = array(
		'Stage',
		'Live'
	);

	private static $extensions = array(
		"Versioned('Stage', 'Live')"
	);

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$fields->removeByName('Providers');

		if ($this->ID) {
			$config = GridFieldConfig_RecordEditor::create();

			$config->removeComponentsByType('GridFieldDetailForm');
			$config->addComponent(new VersionedGridFieldDetailForm());

			/*
			 * This is key to making this work. If we get 'Live' records here
			 * there will be all sorts of issues when only a draft exists.
			 */
			$providerList = $this->Providers()->setDataQueryParam(array(
				'Versioned.mode' => 'stage',
				'Versioned.stage' => 'Stage'
			));

			$gridField = new GridField(
				'Providers',
				'Providers for ' . $this->Name,
				$providerList,
				$config
			);

			$fields->addFieldToTab('Root.Providers', $gridField);
		}

		return $fields;
	}

}
```

Provider DataObject:
```php
<?php

class Provider extends DataObject {

	private static $db = array(
		'Name' => 'Text'
	);

	private static $has_one = array(
		'Service' => 'Service'
	);

	private static $versioning = array(
		'Stage',
		'Live'
	);

	private static $extensions = array(
		"Versioned('Stage', 'Live')"
	);

}
```