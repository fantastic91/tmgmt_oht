<?php /**
 * @file
 * Contains \Drupal\tmgmt_oht\Controller\OhtController.
 */

namespace Drupal\tmgmt_oht\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Route controller class for the tmgmt_oht module.
 */
class OhtController extends ControllerBase {

  public function callback(Request $request) {
    // If translation submitted - handle it.
    if ($_POST['event'] == 'project.resources.new' && $_POST['resource_type'] == 'translation') {
      /**
     * @var TMGMTJobItem $job_item The job object.
     */
    if ($_POST['custom1'] == tmgmt_oht_hash($_POST['custom0']) && $job_item = tmgmt_job_item_load($_POST['custom0'])) {

        /**
         * @var TMGMTOhtPluginController $oht The translator object.
         */
        $oht = $job_item->getTranslator()->getController();
        $oht->retrieveTranslation([$_POST['resource_uuid']], $job_item, $_POST['project_id']);
      }
      else {
        \Drupal::logger('tmgmt_oht')->warning('Wrong call for submitting translation for job item %id', [
          '%id' => $job_item->tjiid
          ]);
      }
    }

//    drupal_exit();
  }

}
