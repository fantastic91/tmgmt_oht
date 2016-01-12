<?php /**
 * @file
 * Contains \Drupal\tmgmt_oht\Controller\OhtController.
 */

namespace Drupal\tmgmt_oht\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt_oht\Plugin\tmgmt\Translator\OhtTranslator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route controller class for the tmgmt_oht module.
 */
class OhtController extends ControllerBase {

  /**
   *
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function callback(Request $request) {
    // If translation submitted - handle it.
    $event = past_event_create('oht', 'item', 'Request var_dump');
    $event->addArgument('request', $request);
    $event->save();

    if ($request->request->get('event') == 'project.resources.new' && $request->request->get('resource_type') == 'translation') {
      /** @var JobItem $job_item */
      if ($request->request->get('custom1') == tmgmt_oht_hash($request->request->get('custom0'))) {
        // && $job_item = tmgmt_job_item_load($_POST['custom0'])) {
        $job_item = JobItem::load($request->request->get('custom0'));
        //$job_item = NULL;

        /** @var OhtTranslator $oht */
        $oht = $job_item->getTranslator()->getPlugin();
        $oht->setEntity($job_item->getTranslator());
        $oht->retrieveTranslation([$request->request->get('resource_uuid')], $job_item, $request->request->get('project_id'));
      }
      else {
        \Drupal::logger('tmgmt_oht')->warning('Wrong call for submitting translation for job item %id', [
          '%id' => 'id',
        ]);
      }
    }

    return new Response();
  }

}
