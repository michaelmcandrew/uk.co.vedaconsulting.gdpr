<?php

require_once 'CRM/Core/Page.php';

class CRM_Gdpr_Utils {
  /**
   * CiviCRM API wrapper
   *
   * @param string $entity
   * @param string $action
   * @param string $params
   *
   * @return array of API results
   */
  public static function CiviCRMAPIWrapper($entity, $action, $params) {

    if (empty($entity) || empty($action) || empty($params)) {
      return;
    }

    try {
      $result = civicrm_api3($entity, $action, $params);
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('CiviCRM API Call Failed');
      CRM_Core_Error::debug_var('CiviCRM API Call Error', $e);
      return;
    }

    return $result;
  }

  /**
   * Get all activity types
   *
   * @return array of activity types ids, title
   */
  public static function getAllActivityTypes() {

    $actTypes = array();

    // Get all membership types from CiviCRM
    $result = self::CiviCRMAPIWrapper('OptionValue', 'get', array(
      'sequential' => 1,
      'is_active' => 1,
      'option_group_id' => "activity_type",
      'options' => array('limit' => 0),
    ));

    if (!empty($result['values'])) {
      foreach($result['values'] as $key => $value) {
        $actTypes[$value['value']] = $value['label'];
      }
    }

    return $actTypes;
  }

  /**
   * Get all contact types
   *
   * @return array of contact types ids, title
   */
  public static function getAllContactTypes($parentOnly = FALSE) {

    $contactTypes = array();

    $contactTypeParams = array(
      'sequential' => 1,
      'is_active' => 1,
    );

    // Check if we need to get only the parent contact types
    if ($parentOnly) {
      $contactTypeParams['parent_id'] = array('IS NULL' => 1);
    }

    // Get all membership types from CiviCRM
    $result = self::CiviCRMAPIWrapper('ContactType', 'get', $contactTypeParams);

    if (!empty($result['values'])) {
      foreach($result['values'] as $key => $value) {
        $contactTypes[$value['name']] = $value['label'];
      }
    }

    return $contactTypes;
  }

  /**
   * Function to get all group subscription
   *
   * @return array()
   */
  public static function getallGroupSubscription($contactId) {
    if (empty($contactId)) {
      return;
    }

    $groupSubscriptions = array();
    $sql = "SELECT c.sort_name, g.title, s.date, s.id, s.contact_id, s.group_id, s.status, g.is_active, CASE WHEN g.visibility = 'Public Pages' THEN 1 ELSE 0 END as is_public FROM
civicrm_subscription_history s
INNER JOIN civicrm_contact c ON s.contact_id = c.id
INNER JOIN civicrm_group g ON g.id = s.group_id
WHERE s.contact_id = %1 ORDER BY s.date DESC";
    $resource = CRM_Core_DAO::executeQuery($sql, array( 1 => array($contactId, 'Integer')));
    while ($resource->fetch()) {
      $groupSubscriptions[$resource->id] = array(
        'id' => $resource->id,
        'contact_id' => $resource->contact_id,
        'group_id' => $resource->group_id,
        'sort_name' => $resource->sort_name,
        'date' => $resource->date,
        'title' => $resource->title,
        'status' => $resource->status,
        'is_active' => $resource->is_active,
        'is_public' => $resource->is_public,
      );
    }

    return $groupSubscriptions;
  }

  /**
   * Function get custom search ID using name
   *
   * @return array $csid
   */
  public static function getCustomSearchDetails($name) {

    if (empty($name)) {
      return;
    }

    // Get all membership types from CiviCRM
    $result = self::CiviCRMAPIWrapper('OptionValue', 'get', array(
      'sequential' => 1,
      'option_group_id' => "custom_search",
      'name' => $name,
    ));

    //MV: Returns lot of notice message when there is no result found.
    if (empty($result['count'])) {
      return array('id' => NULL, 'label' => NULL);
    }
    return array('id' => $result['values'][0]['value'], 'label' => $result['values'][0]['description']);
  }

  /**
   * Function get GDPR settings
   *
   * @return array $settings (GDPR settings)
   */
  public static function getGDPRSettings() {
    // Get GDPR settings from civicrm_settings table
    $settingsStr = CRM_Core_BAO_Setting::getItem(
      CRM_Gdpr_Constants::GDPR_SETTING_GROUP,
      CRM_Gdpr_Constants::GDPR_SETTING_NAME
    );

    return $settingsStr ? unserialize($settingsStr) : array();
  }

  /**
   * Function get GDPR activity types id and label
   *
   * @return array $activityTypes
   */
  public static function getGDPRActivityTypes() {

    $gdprActTypes = array();

    // Get GDPR settings
    $settings = CRM_Gdpr_Utils::getGDPRSettings();

    // Get all activity types
    $actTypes = CRM_Gdpr_Utils::getAllActivityTypes();
    if (!empty($settings['activity_type'])) {
      foreach($settings['activity_type'] as $actTypeId) {
        $gdprActTypes[] = $actTypes[$actTypeId];
      }
    }
    return $gdprActTypes;
  }

  /**
   * Function get contacts count summary who have not had activity is a set period
   * but has done a click through
   *
   * @return array $contactscount
   */
  public static function getContactsWithClickThrough() {
    $count = 0;

    $clickThroughSql = self::getContactClickThroughSQL($getCountOnly = TRUE);
    $resource = CRM_Core_DAO::executeQuery($clickThroughSql);
    if ($resource->fetch()) {
      $count = $resource->count;
    }

    return $count;
  }

  /**
   * Function get contacts count summary who have not had activity is a set period
   *
   * @return array $contactscount
   */
  public static function getNoActivityContactsSummary() {
    $count = 0;

    // Get contact count who have not had any GDPR activities
    $actContactSummarySql = self::getActivityContactSQL($actTypeParams, TRUE, TRUE);
    if ($actContactSummarySql) {
      $resource = CRM_Core_DAO::executeQuery($actContactSummarySql);
      if ($resource->fetch()) {
        $count = $resource->count;
      }
    }

    return $count;
  }

  /**
   * Function get contacts list who have not had activity is a set period
   *
   * @return array $contactList
   */
  public static function getNoActivityContactsList($params) {

    $contactList = array();

    $contactListSql = self::getActivityContactSQL($params, FALSE, TRUE);
    if ($contactListSql) {
      $resource = CRM_Core_DAO::executeQuery($contactListSql);
      while ($resource->fetch()) {

        // get last activity date time
        /*$lastActSql = "SELECT a.activity_date_time FROM civicrm_activity_contact ac
  INNER JOIN civicrm_activity a ON a.id = ac.activity_id
  WHERE ac.record_type_id = 3 AND a.activity_type_id = %1 AND ac.contact_id = %2
  ORDER BY a.activity_date_time LIMIT 1
        ";
        $lastActParams = array(
          1 => array($params['activity_type_id'], 'Integer'),
          2 => array($resource->id, 'Integer'),
        );
        $lastActResource = CRM_Core_DAO::executeQuery($lastActSql, $lastActParams);
        $lastActDateTime = '';
        if ($lastActResource->fetch()) {
          $lastActDateTime = $lastActResource->activity_date_time;
        }*/

        $url = CRM_Utils_System::url('civicrm/contact/view', 'reset=1&cid='.$resource->id);
        $contactList[$resource->id] = array(
          'id' => $resource->id,
          'sort_name' => "<a href='{$url}'>".$resource->sort_name."</a>",
          //'activity_date_time' => '',
        );
      }
    }
    return $contactList;
  }

  /**
   * Get count of contacts for a particular activity type.
   *
   * @param array $params
   *   Associated array for params.
   *
   * @return null|string
   */
  public static function getActivityContactCount(&$params) {
    $count = 0;
    $contactListSql = self::getActivityContactSQL($params, TRUE, TRUE);
    if ($contactListSql) {
      $count = CRM_Core_DAO::singleValueQuery($contactListSql);
    }
    return $count;
  }

  /**
   * Function to compose SQL for getting contacts who have not had an activity
   *
   * @param array $params
   *   Associated array for params.
   *
   * @return where|string
   */
  public static function getActivityContactSQL(&$params, $getCountOnly = FALSE, $excludeClickThrough = FALSE, $getWhereClauseOnly = FALSE) {

    // Get GDPR settings
    $settings = CRM_Gdpr_Utils::getGDPRSettings();
    if (empty($settings['activity_period']) || empty($settings['activity_type'])) {
      return;
    }

    // Get current date - set period
    $date = date('Y-m-d H:i:s', strtotime('-'.$settings['activity_period'].' days'));
    $actTypeIdsStr = implode(',', $settings['activity_type']);

    $orderBy = $limit = '';
    if (!empty($params['context']) && $params['context'] == 'activitycontactlist') {
      $params['offset'] = ($params['page'] - 1) * $params['rp'];
      $params['rowCount'] = $params['rp'];
      $params['sort'] = CRM_Utils_Array::value('sortBy', $params);

      if (!empty($params['rowCount']) && is_numeric($params['rowCount'])
        && is_numeric($params['offset']) && $params['rowCount'] > 0
      ) {
        $limit = " LIMIT {$params['offset']}, {$params['rowCount']} ";
      }

      $orderBy = ' ORDER BY contact_a.id desc';
      if (!empty($params['sort'])) {
        $orderBy = ' ORDER BY ' . CRM_Utils_Type::escape($params['sort'], 'String');
      }
    }

    $extraWhere = '';
    if (!empty($settings['contact_type'])) {
      $contactTypeStr = "'".implode("','", $settings['contact_type'])."'";
      $extraWhere .= " AND contact_a.contact_type IN ({$contactTypeStr})";
    }

    if (!empty($params['contact_name'])) {
      $extraWhere .= " AND contact_a.sort_name LIKE '%{$params['contact_name']}%'";
    }

    $selectColumns = "contact_a.id, contact_a.sort_name";
    if ($getCountOnly) {
      $selectColumns = "count(*) as count";
      $limit = '';
    }

    $excludeClickSql = '';
    if ($excludeClickThrough) {
      $clickThroughSql = self::getContactClickThroughSQL();
      $excludeClickSql = " AND contact_a.id NOT IN ({$clickThroughSql})";
    }

    $whereClause = "contact_a.id NOT IN (
SELECT contact_id FROM civicrm_activity_contact ac
INNER JOIN civicrm_activity a ON a.id = ac.activity_id
WHERE ac.record_type_id IN (2, 3) AND a.activity_type_id IN ({$actTypeIdsStr})
AND a.activity_date_time > '{$date}'
) AND contact_a.is_deleted = 0 {$extraWhere} {$excludeClickSql}";

    $sql = "SELECT {$selectColumns} FROM civicrm_contact contact_a
WHERE {$whereClause} {$orderBy} {$limit}";

    if ($getWhereClauseOnly) {
      return $whereClause;
    } else {
      return $sql;
    }
  }

  /**
   * Function to compose SQL for getting contacts who clicked a link in email
   *
   * @param array $params
   *   Associated array for params.
   *
   * @return where|string
   */
  public static function getContactClickThroughSQL($getCountOnly = FALSE) {
    // Get GDPR settings
    $settings = CRM_Gdpr_Utils::getGDPRSettings();
    if (empty($settings['activity_period'])) {
      return;
    }

    // Get current date - set period
    $date = date('Y-m-d H:i:s', strtotime('-'.$settings['activity_period'].' days'));

    $selectColumns = "queue.contact_id";
    if ($getCountOnly) {
      $selectColumns = "count(*) as count";
    }

    $sql = "SELECT {$selectColumns} FROM civicrm_mailing_event_trackable_url_open url
INNER JOIN civicrm_mailing_event_queue queue ON queue.id = url.event_queue_id
WHERE url.time_stamp > '{$date}'";

    return $sql;
  }

  /**
   * Anonymize a contact.
   *
   * @param int $contactId
   *  Id for a contact.
   * 
   * @return array
   *  Associative array with the format of an API Contact.create result.
   */
  public static function anonymizeContact($contactId) {
    // Should we check contact exists?


    // Retrieve the contact, to check it exists.
    $contactResult = CRM_Gdpr_Utils::CiviCRMAPIWrapper('Contact', 'get', array(
      'id' => $contactId,
      'sequential' => 1,
    ));
    if (empty($contactResult['values'][0])) {
      return $contactResult;
    }
    else {
      $currentContact = $contactResult['values'][0];
    }
    // get all fields of contact API
    $fieldsResult = CRM_Gdpr_Utils::CiviCRMAPIWrapper('Contact', 'getfields', array(
      'sequential' => 1,
    ));

    $fields = array();
    if ($fieldsResult && !empty($fieldsResult['values'])) {
      $fields = $fieldsResult['values'];
    }

    // setting up params to update contact record
    $params = array(
      'sequential' => 1,
    );

    // Loop through fields and set them empty
    $isCiviCRMVersion47 = _gdpr_isCiviCRMVersion47();
    foreach ($fields as $key => $field) {
      //Fix me : skipping if not a core field. We may need to clear the custom fields later
      if ($isCiviCRMVersion47) {
        if ( !array_key_exists('is_core_field', $field) || $field['is_core_field'] != 1 ) {
          continue;
        }
      } else {
        // is_core_field is not supported in 4.6, so we only anonymize fields with
        // 'where' clause that start with 'civicrm_contact.' since they are usually
        // the core civi fields.
        if ( !array_key_exists('where', $field) || strpos($field['where'], 'civicrm_contact.') != 0) {
          continue;
        }
      }


      $fieldName = $field['name'];
      $params[$fieldName] = '';
    }

    // Add contact ID into params to update the contact record
    $params['id'] = $contactId;
    // Set diplay name as Anonymous by default
    $params['last_name'] = 'Anonymous';

    // Get Display Name from GDPR settings
    $settings = CRM_Gdpr_Utils::getGDPRSettings();
    if (!empty($settings['forgetme_name'])) {
      $params['last_name'] = $settings['forgetme_name'];
    }

    // Allow params to be modified via hook
    CRM_Gdpr_Hook::alterAnonymizeContactParams($params);

    $updateResult = CRM_Gdpr_Utils::CiviCRMAPIWrapper('Contact', 'create', $params);
    $associatedResult = self::deleteContactAssociatedData($contactId, array(
      'Email', 
      'Phone',
      'IM',
      'Website',
    ));

    // Update all active memberships to 'GDPR Cancelled'
    self::cancelAllActiveMemberships($contactId);

    return $updateResult;
  }

  /**
   * Cancels all active memberships of the contact
   * and updates status to 'GDPR Cancelled'
   *
   * @param int $contactId
   *
   */
  static function cancelAllActiveMemberships($contactId) {
    self::CiviCRMAPIWrapper('Membership', 'get', array(
      'sequential' => 1,
      'contact_id' => $contactId,
      'active_only' => 1,
      'api.Membership.create' => array(
        'id' => "\$value.id",
        'status_id' => "GDPR_Cancelled",
        'is_override' => 1,
      ),
    ));
  }

  
  /**
   * Deletes data directly associated with a contact.
   *
   * @param int $contactId
   *
   * @param array $types
   *  Array containing the names of types to delete, may include:
   *   - Email
   *   - Phone
   *   - IM
   *   - Website
   *   - Address
   */
  static function deleteContactAssociatedData($contactId, $types = array('Email', 'Phone')) {
    $validTypes = array('Email', 'Phone', 'IM', 'Website', 'Address');
    $delResult = array();
    foreach ($types as $entity) {
      if (!in_array($entity, $validTypes)) {
        continue;
      }
      $getResult = CRM_Gdpr_Utils::CiviCRMAPIWrapper($entity, 'get', array(
        'sequential' => 1,
        'contact_id' => $contactId,
      ));

      if (!empty($getResult['values'])) {
        foreach ($getResult['values'] as $data) {
          $id = $data['id'];
          $delResult[$entity][$id] = CRM_Gdpr_Utils::CiviCRMAPIWrapper($entity, 'delete', array(
            'id' => $id,
            'sequential' => 1,
          ));
        }
      }
    }
    return $delResult;
  }

}//End Class
