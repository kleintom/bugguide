<?php

/**
 * @file
 * Postmigration script for Bugguide. Runs on Drupal 7.
 *
 * The purpose of this script is to ensure that each bgimage has a unique
 * hash in the bgimage table (base column) and in field_data_field_bgimage_base.
 */


// Run with drush php-script unique_image_hashes.php
require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
require_once DRUPAL_ROOT . '/includes/file.inc';
require_once DRUPAL_ROOT . '/includes/module.inc';
require_once DRUPAL_ROOT . '/sites/all/modules/custom/bgimage/bgimage.module';

if (!drupal_is_cli()) {
  echo "This code is restricted to the command line.";
  exit();
}

function hashlog($s) {
    echo $s . "\n";
    echo "";
}

drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
hashlog("booted up");
// Walk through bgimage table.
$result = db_query("SELECT nid, base FROM bgimage ORDER BY nid LIMIT 1");
foreach ($result as $record) {
    print_r($record);
  // Generate a guaranteed-unique uuid.
  $uuid = bgimage_generate_uuid();
  //$uuid = $record->newbase;
  
  // 1. Old filepath
  $old_filepath = image_obfuscate($record->base . $record->nid) . '.jpg';
  hashlog("old_filepath: $old_filepath");


  // 2. New filepath
  $obfuscate = image_obfuscate($uuid);
  hashlog("new_filepath: $obfuscate");
  
  // 3. If we have not already done so, copy the file.
  $obfuscate = image_obfuscate($uuid);
  $prefix = bgimage_get_prefix($obfuscate);
  $directory = 'public://raw/' . $prefix;
  hashlog ("new directory: $directory");
  // Ensure directory
  if (!file_prepare_directory($directory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS)) {
    watchdog('image', 'Failed to create style directory: %directory', array('%directory' => $directory), WATCHDOG_ERROR);
    hashlog( "aaaugh, could not create style directory $directory");
    return FALSE;
  }

  $uri = $directory . $obfuscate . '.jpg';
  hashlog( "uri: $uri");
  // File will exist if a previous migration already converted this file.
  $fid = db_query("SELECT fid FROM {file_usage} WHERE id = :nid", array(':nid' => $record->nid))->fetchField();
  $file = file_load($fid);
  hashlog("Loaded file:");
  print_r($file);
  // Save original uri; there is a file there.
  $raw_file_uri = $file->uri;
  if (!file_exists($uri)) {
    // Munge the uri to point to raworig, an archive of files with current BugGuide paths
    $file->uri = str_replace('raw', 'raworig', $file->uri);
    // Copy file into new path, e.g.,
    // files/raw/7H3/HEH/7H3HEHUZ6H3HMLUZ8LWZUHLRUHVZMLZR5LAZ8LUZ6HBH0L6Z4H6ZLLOHIHBHQL5Z8H.jpg
    
    // TODO: file_copy is munging around with database entries, maybe we want to bypass.
    file_copy($file, $directory . $obfuscate . '.jpg', FILE_EXISTS_ERROR); 
    // 4. Update the uri in file_managed
    // but file_copy() does not update the filename so we have to do that
    db_query("UPDATE {file_managed} SET filename = :filename WHERE fid = :fid", array(':filename' => $obfuscate . '.jpg', ':fid' => $fid));
  }
  else {
      hashlog("file already exists");
  }
  // TODO delete $raw_file_url which is the original image path.
  
  
  // 5. Update the hash in bgimage
  db_query("UPDATE {bgimage} SET base = :uuid WHERE nid = :nid", array(':uuid' => $uuid, ':nid' => $record->nid));
  
  // 6. Update the hash in field_data_bgimage_base
  db_query("UPDATE {field_data_field_bgimage_base} SET field_bgimage_base_value = :uuid WHERE entity_id = :nid", array(':uuid' => $uuid, ':nid' => $record->nid));
  
  // 7. Update the has in field_revisions_bgimage_base
  db_query("UPDATE {field_revision_field_bgimage_base} SET field_bgimage_base_value = :uuid WHERE entity_id = :nid", array(':uuid' => $uuid, ':nid' => $record->nid));
  
  hashlog("");
  hashlog("$record->nid $record->base -> $uuid");
  hashlog("old: $old_filepath");
  hashlog("new: $directory$obfuscate.jpg");
  hashlog("");
  hashlog("Doing a check. Loading nid $record->nid");
  // clear node cache
  $node = node_load($record->nid, NULL, TRUE);
  hashlog("Base should be: $uuid");
  hashlog("Base is: " . $node->{'field_bgimage_base'}[LANGUAGE_NONE][0]['value']);
  hashlog("Checking entry in files_managed:");
  $fidcheck = db_query("SELECT fid FROM {file_usage} WHERE id = :nid", array(':nid' => $record->nid))->fetchField();
  hashlog("fid is $fidcheck in files_usage associated with nid $record->nid");
  hashlog ("Checking entry in files_managed");
  $filecheck = db_query("SELECT filename FROM {file_managed} WHERE fid = :fid", array(':fid' => $fidcheck))->fetchField();
  hashlog("filename is $filecheck");
  $fileloadcheck = file_load($fidcheck);
  print_r($fileloadcheck);
  hashlog("-------");
  hashlog("");
}