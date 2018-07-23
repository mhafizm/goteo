<?php

/*
* Model for tax relief
*/

namespace Goteo\Model;

use Goteo\Application\Exception\ModelNotFoundException;
use Goteo\Application\Lang;
use Goteo\Application\Config;



class Sphere extends \Goteo\Core\Model {

    public
    $id,
    $name,
    $image;


    public static function getLangFields() {
        return ['name'];
    }

    /**
     * Get data about a sphere
     *
     * @param   int    $id         sphere id.
     * @return  Sphere object
     */
    static public function get($id) {

        $lang = Lang::current();

        if(self::default_lang($lang) === Config::get('lang')) {
          $different_select=" IFNULL(sphere_lang.name, sphere.name) as name";
        }
        else {
          $different_select=" IFNULL(sphere_lang.name, IFNULL(eng.name,sphere.name)) as name";
          $eng_join=" LEFT JOIN sphere_lang as eng
                            ON  eng.id = sphere.id
                            AND eng.lang = 'en'";
        }

        $values=['id' => $id, 'lang' => $lang];

        $sql="SELECT
                    sphere.id as id,
                    sphere.image as image,
                    $different_select
              FROM sphere
              LEFT JOIN sphere_lang
                    ON  sphere_lang.id = sphere.id
                    AND sphere_lang.lang = :lang
              $eng_join
              WHERE sphere.id = :id";
        $query = static::query($sql, $values);
        $item = $query->fetchObject(__CLASS__);

        if($item) {
            if($item->image)
                    $item->image = Image::get($item->image);
            return $item;
        }

        throw new ModelNotFoundException("Sphere not found for ID [$id]");
    }


    public function getImage() {
        if($this->image instanceOf Image) return $this->image;
        if($this->image) {
            $this->image = Image::get($this->image);
        } else {
            $this->image = new Image();
        }
        return $this->image;
    }

    /**
     * Sphere list
     *
     * @param  array  $filters
     * @return mixed            Array of spheres
     */
    public static function getAll($filters = array()) {

        $lang = Lang::current();

        $values = [];
        $filter = [];

        $list = [];

        $values[':lang']=$lang;

        if($filters['landing_match']) {
            $filter[] = "sphere.landing_match=1";
        }

        if($filter) {
            $sql = " WHERE " . implode(' AND ', $filter);
        }

        if(self::default_lang($lang) === Config::get('lang')) {
          $different_select=" IFNULL(sphere_lang.name, sphere.name) as name";
        }
        else {
          $different_select=" IFNULL(sphere_lang.name, IFNULL(eng.name,sphere.name)) as name";
          $eng_join=" LEFT JOIN sphere_lang as eng
                            ON  eng.id = sphere.id
                            AND eng.lang = 'en'";
        }

        $sql = "SELECT  sphere.id as id,
                        sphere.image as image,
                        $different_select
                FROM sphere
                LEFT JOIN sphere_lang
                    ON  sphere_lang.id = sphere.id
                    AND sphere_lang.lang = :lang
                $eng_join
                $sql 
                ORDER BY name ASC";


        $query = self::query($sql, $values);
        //print(\sqldbg($sql, $values));

        if($query = self::query($sql, $values)) {
            return $query->fetchAll(\PDO::FETCH_CLASS, __CLASS__);
        }

        return [];

    }

    /**
     * Save.
     *
     * @param   type array  $errors
     * @return  type bool   true|false
     */
    public function save(&$errors = array()) {

        if (!$this->validate($errors))
            return false;

        // Save opcional image
        if (is_array($this->image) && !empty($this->image['name'])) {
            $image = new Image($this->image);

            if ($image->save($errors)) {
                $this->image = $image->id;
            } else {
                \Goteo\Application\Message::error(Text::get('image-upload-fail') . implode(', ', $errors));
                $this->image = '';
            }
        }
        if (is_null($this->image)) {
            $this->image = '';
        }

        $fields = array(
            // 'id',
            'name',
            'image'
        );

        try {
            $this->dbInsertUpdate($fields);

            return true;
        } catch(\PDOException $e) {
            $errors[] = "Sphere save error: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Validate.
     *
     * @param   type array  $errors     Errores devueltos pasados por referencia.
     * @return  type bool   true|false
     */
    public function validate(&$errors = array()) {

        return true;
    }


}


