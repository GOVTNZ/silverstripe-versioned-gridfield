<?php

/**
 * VersionedModelAdmin
 * replaces the scaffolded gridfield for versioned objects with a VersionedGridFieldDetailForm
 * See README for details 
 */
class VersionedModelAdmin extends Extension {

	public function updateEditForm(CMSForm $form) {
		$fieldList = $form->Fields();

		foreach($fieldList as $field) {
			if($field instanceof GridField) {
				$class = $field->getList()->dataClass();
				if($class::has_extension($class, "Versioned")) {
					$field->setList(Versioned::get_by_stage($class, 'Stage'));

					$config = $field->getConfig();
					$config->removeComponentsByType('GridFieldDeleteAction')
						->removeComponentsByType('GridFieldDetailForm')
						->addComponents(new VersionedGridFieldDetailForm());
					$field->setConfig($config);
				}
			}
		}
	}

}
