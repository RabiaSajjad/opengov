<?php

namespace Drupal\mergenodes\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\NodeType;
use Drupal\media_entity\Entity\Media;

/**
 * Class DefaultForm.
 */
class DefaultForm extends FormBase {


  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mergeNodesForm';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // generate a list of content types
    $all_content_types = NodeType::loadMultiple();
    $content_types = array();
    foreach ($all_content_types as $machine_name => $content_type) {
      $content_types[$content_type->id()] = $content_type->label();
    }
    ksort($content_types);

    // create a simple multi select list of content types
    $form['contenttype'] = [
      '#type' => 'select',
      '#title' => $this->t('Select a content type to merge'),
      '#options' => $content_types,
      '#size' => count($content_types),
      '#weight' => '0',
    ];

    if ($form_state->isSubmitted()) {
      // get selected content type
      $content_type = $form_state->getValue('contenttype');
      if (empty($content_type)) {
        drupal_set_message("No content type selected for mapping", 'warning');
      }
      else {
        $comparefield = $content_type == 'external' ? 'field_uuid' : 'field_previousnodeid';
        // fetch all nodes of content type
        $query = \Drupal::entityQuery('node');
        $query->condition('type', $content_type);
        $query->exists($comparefield);
        $query->sort($comparefield);
        $query->sort('langcode');
        $nids = $query->execute();
        $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);

        // generate a mapping table of translations
        $rows = array();
        foreach ($nodes as $node) {
          if ($node->get('langcode')->value == 'en') {
            foreach ($nodes as $node_translation) {
              if (($node_translation->get($comparefield)->value == ($node->get($comparefield)->value))
                and ($node_translation->get('langcode')->value == 'fr')) {
                $rows[] = array(
                  $node->get('title')->value,
                  $node_translation->get('title')->value,
                );
              }
            }
          }
        }

        // generate a table of mappings to render
        $form['mapping'] = [
          '#type' => 'table',
          '#header' => [$this->t('English'), $this->t('French')],
          '#rows' => $rows,
        ];

      }
    }

    // button to view node mapping
    $form['view_mapping'] = array(
      '#name' => 'view_mappings',
      '#type' => 'submit',
      '#value' => t('View Mapping'),
      '#submit' => array([$this, 'viewMappings']),
    );

    // submit button
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Merge translations'),
    ];

    // button to copy blog images to media
    $form['copy_image_media'] = array(
      '#name' => 'copy_image_media',
      '#type' => 'submit',
      '#value' => t('Copy Blog image field to media field'),
      '#submit' => array([$this, 'copyImageMedia']),
    );

    return $form;
  }

  public function viewMappings(array &$form, FormStateInterface &$form_state) {
    $form_state->setRebuild();
  }

  public function copyImageMedia(array &$form, FormStateInterface &$form_state) {
    // 1. Load all blog nodes
    $query = \Drupal::entityQuery('node');
    $query->condition('type', 'article');
    $nids = $query->execute();
    $keys = array_keys($nids);
    $storage_handler = \Drupal::entityTypeManager()->getStorage("node");
    for($i = 0; $i < sizeof($keys); $i++) {
      // 2. Load a node
      $node = $storage_handler->load($nids[$keys[$i]]);
      $file = $node->get('field_image');
      if ($file[0]) {
        // 3. Get image of node and copy it to media
        $file_id = $file[0]->target_id;
        $node->set('field_media', $file_id);
        $node->save();
        $media = $node->get('field_media');
        $media_id = $media[0] ? $media[0]->value : 'undefined';
        drupal_set_message($node->label() . ' Node id = ' . $node->id() . ' image_id = ' . $file_id . ' media id =' . $media_id);
      }
      else {
        drupal_set_message($node->label() . ' Node id = ' . $node->id() . ' No file_id', 'error');
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $content_type = $form_state->getValue('contenttype');
    if (empty($content_type)) {
      drupal_set_message('No content type selected to merge', 'warning');
    }
    else {
      $comparefield = $content_type == 'external' ? 'field_uuid' : 'field_previousnodeid';

      // 1. Fetch all nodes of content type
      $query = \Drupal::entityQuery('node');
      $query->condition('type', $content_type);
      $query->exists($comparefield);
      $query->sort($comparefield);
      $query->sort('langcode');
      $nids = $query->execute();
      $keys = array_keys($nids);
      $storage_handler = \Drupal::entityTypeManager()->getStorage("node");

      for($i = 0; $i < sizeof($keys); $i++) {
        // 2. Load the field_previousnodeid for the first node
        $node1 = $storage_handler->load($nids[$keys[$i]]);
        $node1_previousnodeid = $node1->get($comparefield)->getValue();
        $node1_langcode = $node1->language()->getId();

        // 3. check the field_previousnodeid for the next node
        $node2 = $storage_handler->load($nids[$keys[$i+1]]);
        if ($node2) {
          $node2_previousnodeid = $node2->get($comparefield)->getValue();
          $node2_langcode = $node2->language()->getId();

          if (($node1_previousnodeid[0]['value'] == $node2_previousnodeid[0]['value'])
            and ($node1_langcode != $node2_langcode)) {
            // 4. If both have same value for field_previousnodeid merge the second node as a translation and delete after merging
            $node1->addTranslation($node2_langcode, $node2->getTranslation($node2_langcode)->toArray());
            $node1->save();
            $node2->delete();
            drupal_set_message('Merged translation' . $node2->get('title')->value . ' with ' . $node1->get('title')->value );

            // move counter one ahead
            $i++;
          }
          else {
            drupal_set_message('No translation found for ' . $node1->get('title')->value, 'error');
          }
        }
      }
    }

  }

}
