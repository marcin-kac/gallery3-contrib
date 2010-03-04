<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2010 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Json_Album_Controller extends Controller {


  private function child_json_encode($child){
    return array(
      'id' => $child->id,
      'title' => $child->title,
      'type' => $child->type,
      'thumb' => $child->thumb_url(),
      'resize' => $child->resize_url(),
      'sort' => $child->sort_column);
  }

  private function child_elements($item_id, $where = array()) {
    $item = ORM::factory("item", $item_id);
    access::required("view", $item);

    $children = $item->children(null, null, $where);
    $encoded = array();
    foreach ($children as $id => $child){
      $encoded[$id] = self::child_json_encode($child);
    }

    return json_encode($encoded);
  }

  function is_admin() {
    if (identity::active_user()->admin) {
      print json_encode(array("result" => "success", "csrf" => access::csrf_token()));
      return;
    }
    print json_encode(array("result" => "failure"));

  }

  function albums($item_id) {
    print $this->child_elements($item_id, array(array("type", "=", "album")));
  }

  function children($item_id){

    print $this->child_elements($item_id);
  }

  function item($item_id){

    $item = ORM::factory("item", $item_id);
    access::required("view", $item);
    print json_encode(self::child_json_encode($item));
  }


  function move_to($target_album_id) {
    access::verify_csrf();

    $target_album = ORM::factory("item", $target_album_id);

    $js = json_decode($_REQUEST["sourceids"]);

    $i = 0;
    foreach ($js as $source_id) {
      $source = ORM::factory("item", $source_id);
      if (!$source->contains($target_album)) {
        item::move($source, $target_album);
      }
      $i++;
    }

    print json_encode(array("result" => "success"));
  }

 function rearrange($target_id, $before_or_after) {
    access::verify_csrf();
    $target = ORM::factory("item", $target_id);
    $album = $target->parent();
    access::required("view", $album);
    access::required("edit", $album);

    $source_ids = json_decode($_REQUEST["sourceids"]);

    if ($album->sort_column != "weight") {
      $i = 0;
      foreach ($album->children() as $child) {
        // Do this directly in the database to avoid sending notifications
        db::build()
          ->update("items")
          ->set("weight", ++$i)
          ->where("id", "=", $child->id)
          ->execute();
      }
      $album->sort_column = "weight";
      $album->sort_order = "ASC";
      $album->save();
      $target->reload();
    }

    // Find the insertion point
    $target_weight = $target->weight;
    if ($before_or_after == "after") {
      $target_weight++;
    }

    // Make a hole
    $count = count($source_ids);
    db::build()
      ->update("items")
      ->set("weight", new Database_Expression("`weight` + $count"))
      ->where("weight", ">=", $target_weight)
      ->where("parent_id", "=", $album->id)
      ->execute();
    // Insert source items into the hole
    foreach ($source_ids as $source_id) {
      db::build()
        ->update("items")
        ->set("weight", $target_weight++)
        ->where("id", "=", $source_id)
        ->execute();
    }

    module::event("album_rearrange", $album);

    print json_encode(array("result" => "success"));

  }

  public function start() {
    batch::start();
  }

  public function add_photo($id) {
    access::verify_csrf();
    $album = ORM::factory("item", $id);
    access::required("view", $album);
    access::required("add", $album);


    try {
      $name = $_REQUEST["filename"];
      $body = @file_get_contents('php://input');
      //$stream  = http_get_request_body();

      $directory = Kohana::config('upload.directory', TRUE);

      // Make sure the directory ends with a slash
      $directory = str_replace('\\','/',$directory);
     $directory = rtrim($directory, '/').'/';

      if ( ! is_dir($directory) AND Kohana::config('upload.create_directories') === TRUE)
      {
        // Create the upload directory
        mkdir($directory, 0777, TRUE);
      }

      if ( ! is_writable($directory))
        throw new Kohana_Exception('upload.not_writable', $directory);

      $temp_filename = $directory.$name;
      $file = fopen($temp_filename,'w');

      fwrite($file,$body);

      fclose($file);



        $title = item::convert_filename_to_title($name);
        $path_info = @pathinfo($temp_filename);
        if (array_key_exists("extension", $path_info) &&
            in_array(strtolower($path_info["extension"]), array("flv", "mp4"))) {
          $item = ORM::factory("item");
          $item->type = "movie";
          $item->parent_id = $album->id;
          $item->set_data_file($temp_filename);
          $item->name = $name;
          $item->title = $title;
          $item->save();
          log::success("content", t("Added a movie"),
                       html::anchor("movies/$item->id", t("view movie")));
        } else {
          $item = ORM::factory("item");
          $item->type = "photo";
          $item->parent_id = $album->id;
          $item->set_data_file($temp_filename);
          $item->name = $name;
          $item->title = $title;
          $item->save();
          log::success("content", t("Added a photo"),
                       html::anchor("photos/$item->id", t("view photo")));
        }
      } catch (Exception $e) {
        Kohana::log("alert", $e->__toString());
        if (file_exists($temp_filename)) {
          unlink($temp_filename);
        }
        header("HTTP/1.1 500 Internal Server Error");
        print "ERROR: " . $e->getMessage();
        return;
      }
      unlink($temp_filename);

      print json_encode(self::child_json_encode($item));
  }

  public function make_album_cover($id) {
    access::verify_csrf();

    $item = model_cache::get("item", $id);
    access::required("view", $item);
    access::required("view", $item->parent());
    access::required("edit", $item->parent());

    item::make_album_cover($item);

    print json_encode(array("result" => "success"));
  }

    public function rotate($id, $dir) {
    access::verify_csrf();
    $item = model_cache::get("item", $id);
    access::required("view", $item);
    access::required("edit", $item);

    $degrees = 0;
    switch($dir) {
    case "ccw":
      $degrees = -90;
      break;

    case "cw":
      $degrees = 90;
      break;
    }

    if ($degrees) {
      gallery_graphics::rotate($item->file_path(), $item->file_path(), array("degrees" => $degrees));

      list($item->width, $item->height) = getimagesize($item->file_path());
      $item->resize_dirty= 1;
      $item->thumb_dirty= 1;
      $item->save();

      graphics::generate($item);

      $parent = $item->parent();
      if ($parent->album_cover_item_id == $item->id) {
        copy($item->thumb_path(), $parent->thumb_path());
        $parent->thumb_width = $item->thumb_width;
        $parent->thumb_height = $item->thumb_height;
        $parent->save();
      }
    }

    print json_encode(self::child_json_encode($item));
  }

  public function resize_config(){
    if (upload_configuration::isResize())
    {
      print json_encode(array(
        "resize" => true,
        "max_width" => upload_configuration::getMaxWidth(),
        "max_height" => upload_configuration::getMaxHeight()));
    }
    else
    {
      print json_encode(array("resize" => false));
    }
  }

}