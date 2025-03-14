<?php
/*
* This file is part of the Goteo Package.
*
* (c) Platoniq y Fundación Goteo <fundacion@goteo.org>
*
* For the full copyright and license information, please view the README.md
* and LICENSE files that was distributed with this source code.
*/

namespace Goteo\Model;

use Goteo\Application;
use Goteo\Application\Config;
use Goteo\Application\Lang;
use Goteo\Application\Message;
use Goteo\Application\Session;
use Goteo\Application\Exception\ModelException;
use Goteo\Core\Exception;
use Goteo\Library\Check;
use Goteo\Library\Text;
use Goteo\Library\Password;
use Goteo\Model\Image;
use Goteo\Model\Mail;
use Goteo\Model\Node;
use Goteo\Model\Project;
use Goteo\Model\Project\Favourite;
use Goteo\Model\Template;
use Goteo\Model\User\Pool as UserPool;
use Goteo\Model\User\UserLocation;
use Goteo\Model\User\Web as UserWeb;
use Goteo\Model\User\Interest as UserInterest;
use Goteo\Model\User\UserRoles;

class User extends \Goteo\Core\Model {

    public
    $id = false,
    $lang,
    $node, // Nodo al que pertenece
    $nodeData, // Datos del nodo
    $userid, // para el login name al registrarse
    $email,
    $password, // para gestion de super admin
    $birthyear,
    $gender,
    $entity_type,
    $legal_entity,
    $name,
    $location,
    $avatar = null, // Always a Image class
    $user_avatar = '', //image string
    $about,
    $contribution,
    $keywords,
    $active, // si no activo, no puede loguear
    $confirmed, // si no ha confirmado el email
    $hide, // si oculto no aparece su avatar en ninguna parte (pero sus aportes cuentan)
    $facebook,
    $twitter,
    $identica,
    $linkedin,
    $instagram,
    $amount,
    $worth,
    $rememberme,
    $created,
    $modified,
    $interests = array(),
    $webs = array(),
    $roles = array();

    // Cambia el valor por defecto de $punto solo para la clase User
    public static function idealiza($value, $punto = true, $enye = false, $max = 50) {
        return parent::idealiza($value, $punto, $enye, $max);
    }

    public function __construct() {
        $args = func_get_args();
        call_user_func_array(array('parent', '__construct'), $args);
        $this->avatar = new Image($this->avatar);
        if (empty($this->node)) {
            $this->node = Config::get('current_node');
        }

    }

    /**
     * Sobrecarga de métodos 'getter'.
     *
     * @param type string $name
     * @return type mixed
     */
    public function __get($name) {
        if ($name == "get_numInvested") {
            return self::numInvested($this->id);
        }
        if ($name == "support") {
            return $this->getSupport();
        }
        if ($name == "get_numOwned") {
            return self::updateOwned($this->id);
        }
        if ($name == "get_worth") {
            return self::updateWorth($this->id, $this->amount);
        }
        if ($name == "get_amount") {
            return self::updateAmount($this->id);
        }
        if ($name == "geoloc") {
            return $this->getLocation();
        }
        if ($name == "unlocable") {
            return UserLocation::isUnlocable($this->id);
        }
        return $this->$name;
    }


    public static function getLangFields() {
        return ['name', 'about'];
    }

    public function getImage() {
        if(!$this->imageInstance instanceOf Image) {
            $this->imageInstance = new Image($this->avatar);
        }
        return $this->imageInstance;
    }

    /**
     * Guardar usuario.
     * Guarda los valores de la instancia del usuario en la tabla.
     *
     * @param type array    $errors            Errores devueltos pasados por referencia.
     * @param type array    $skip_validations  Crea el usuario aunque estos campos no sean correctos
     *                                         password, active
     * @return type bool    true|false
     */
    public function save(&$errors = array(), $skip_validations = array()) {
        $data = array();


        if ($this->validate($errors, $skip_validations)) {
            // Nuevo usuario.
            if (empty($this->id)) {
                $insert = true;
                $this->id = static::idealiza($this->userid);
                $data[':id'] = $this->id;
                $data[':name'] = $this->name;
                $data[':location'] = $this->location;
                $data[':email'] = $this->email;
                $data[':token'] = $token = md5(uniqid());

                // Check if password is already encoded
                if ($this->password && !in_array('password', $skip_validations)) {
                    if(!Password::isBlowfish($this->password)) {
                       $data[':password'] = Password::encode($this->password);
                    }
                }

                $data[':created'] = date('Y-m-d H:i:s');
                $data[':active'] = false;
                $data[':confirmed'] = false;
                $data[':lang'] = Lang::current();
                $data[':node'] = $this->node;

                //active = 1 si no se quiere comprovar
                if (in_array('active', $skip_validations) && $this->active) {
                    $data[':active'] = true;
                } else {
                    // Activate all users by default. TODO: by config
                    $data[':active'] = true;
                    if (Mail::createFromTemplate($this->email, $this->name, Template::CONFIRM_REGISTER, [
                        '%USERNAME%' => $this->name,
                        '%USERID%' => $this->id,
                        '%USERPWD%' => $this->password,
                        '%ACTIVATEURL%' => Config::getMainUrl() . '/user/activate/' . $token,
                    ])->send($errors)) {
                        Application\Message::info(Text::get('register-confirm_mail-success'));
                    } else {
                        Application\Message::error(Text::get('register-confirm_mail-fail', Config::getMail('mail')));
                        Application\Message::error(implode('<br />', $errors));
                    }
                }
            } else {
                $data[':id'] = $this->id;

                if (!is_null($this->active)) {
                    $data[':active'] = $this->active;
                }

                if (!is_null($this->confirmed)) {
                    $data[':confirmed'] = $this->confirmed;
                }

                if (!is_null($this->hide)) {
                    $data[':hide'] = $this->hide;
                }

                // Avatar
                if ((is_array($this->user_avatar) && !empty($this->user_avatar['name'])) || ($this->user_avatar instanceOf Image && $this->user_avatar->tmp)) {
                    $image = new Image($this->user_avatar);

                    // print_r($image);$image->validate($errors);print_r($errors);die;
                    if ($image->save($errors, false)) {
                        $data[':avatar'] = $image->id;
                        $this->avatar = $image;
                    } else {
                        unset($data[':avatar']);
                    }
                } else if ($this->user_avatar instanceOf Image) {
                    $data[':avatar'] = $this->user_avatar->id;
                    $this->avatar = $this->user_avatar;
                }

                if (empty($this->user_avatar)) {
                    $data[':avatar'] = '';
                    $this->avatar->remove();
                }

                // Perfil público
                if (isset($this->name)) {
                    $data[':name'] = $this->name;
                }

                // Dónde está
                if (isset($this->location)) {
                    $data[':location'] = $this->location;
                }

                if (isset($this->about)) {
                    $data[':about'] = $this->about;
                }

                if (isset($this->keywords)) {
                    $data[':keywords'] = $this->keywords;
                }

                if (isset($this->contribution)) {
                    $data[':contribution'] = $this->contribution;
                }

                if (isset($this->facebook)) {
                    $data[':facebook'] = $this->facebook;
                }

                if (isset($this->twitter)) {
                    $data[':twitter'] = $this->twitter;
                }

                if (isset($this->instagram)) {
                    $data[':instagram'] = $this->instagram;
                }

                if (isset($this->identica)) {
                    $data[':identica'] = $this->identica;
                }

                if (isset($this->linkedin)) {
                    $data[':linkedin'] = $this->linkedin;
                }

                if (isset($this->birthyear)) {
                    $data[':birthyear'] = $this->birthyear;
                }
                if (isset($this->gender)) {
                    $data[':gender'] = $this->gender;
                }
                if (isset($this->entity_type)) {
                    $data[':entity_type'] = $this->entity_type;
                }
                if (isset($this->legal_entity)) {
                    $data[':legal_entity'] = $this->legal_entity;
                }

                // Interests
                static::query('DELETE FROM user_interest WHERE user= ?', $this->id);
                if (!empty($this->interests)) {
                    foreach ($this->interests as $interest) {
                        if ($interest instanceof UserInterest) {
                            $interest->user = $this->id;
                            $interest->save($errors);
                        }
                    }
                    $this->interests = UserInterest::get($this->id);
                    // print_r($this->interests);die;
                }

                // Webs
                static::query('DELETE FROM user_web WHERE user= ?', $this->id);
                if (!empty($this->webs)) {
                    foreach ($this->webs as $web) {
                        if ($web instanceof UserWeb) {
                            $web->user = $this->id;
                            $web->save($errors);
                        }
                    }
                    $this->webs = UserWeb::get($this->id);
                }
            }

            try {
                // Construye SQL.
                if (isset($insert) && $insert == true) {
                    $query = "INSERT INTO user (";
                    foreach ($data AS $key => $row) {
                        $query .= substr($key, 1) . ", ";
                    }
                    $query = substr($query, 0, -2) . ") VALUES (";
                    foreach ($data AS $key => $row) {
                        $query .= $key . ", ";
                    }
                    $query = substr($query, 0, -2) . ")";
                } else {
                    $query = "UPDATE user SET ";
                    foreach ($data AS $key => $row) {
                        if ($key != ":id") {
                            $query .= substr($key, 1) . " = " . $key . ", ";
                        }
                    }
                    $query = substr($query, 0, -2) . " WHERE id = :id";
                }
                 //die(\sqldbg($query, $data));
                // Ejecuta SQL.
                if (self::query($query, $data)) {
                    return true;
                }
            } catch (\PDOException $e) {
                $errors[] = "Error updating user's data: " . $e->getMessage();
                return false;
            }
        }
        return false;
    }

    /**
     * Validación de datos de usuario.
     *
     * @param type array $errors               Errores devueltos pasados por referencia.
     * @param type array    $skip_validations  Crea el usuario aunque estos campos no sean correctos
     *                                         password, active
     * @return bool true|false
     */
    public function validate(&$errors = array(), $skip_validations = array()) {
        // Nuevo usuario.
        if (empty($this->id)) {
            // Nombre de usuario (id)
            if (empty($this->userid)) {
                $errors['userid'] = Text::get('error-register-userid');
            } else {
                $id = self::idealiza($this->userid);
                $query = self::query('SELECT id FROM user WHERE id = ?', array($id));
                if ($query->fetchColumn()) {
                    $errors['userid'] = Text::get('error-register-user-exists'). " ($id)";
                }
            }

            if (empty($this->name)) {
                $errors['username'] = Text::get('error-register-username');
            }

            // E-mail
            if (empty($this->email)) {
                $errors['email'] = Text::get('mandatory-register-field-email');
            } elseif (!Check::mail($this->email)) {
                $errors['email'] = Text::get('validate-register-value-email');
            } else {
                $query = self::query('SELECT email FROM user WHERE email = ?', array($this->email));
                if ($query->fetchObject()) {
                    $errors['email'] = Text::get('error-register-email-exists');
                }
            }

            // Contraseña
            if (!in_array('password', $skip_validations)) {
                if (!empty($this->password)) {
                    if (!Check::password($this->password)) {
                        $errors['password'] = Text::get('error-register-invalid-password');
                    }
                } else {
                    $errors['password'] = Text::get('error-register-pasword-empty');
                }
            }
            return empty($errors);
        }
        // Modificar usuario.
        else {
            if (!empty($this->email)) {
                if (count($tmp = explode('¬', $this->email)) > 1) {
                    if ($this->email !== $this->getToken()) {
                        $errors['email'] = Text::get('error-user-email-token-invalid');
                    }
                } elseif (!Check::mail($this->email)) {
                    $errors['email'] = Text::get('error-user-email-invalid');
                } else {
                    $query = self::query('SELECT id FROM user WHERE email = ?', array($this->email));
                    if ($found = $query->fetchColumn()) {
                        if ($this->id !== $found) {
                            $errors['email'] = Text::get('error-user-email-exists');
                        }
                    }
                }
            }
            if (!empty($this->password)) {
                if (!Check::password($this->password)) {
                    $errors['password'] = Text::get('error-user-password-invalid');
                }
            }

        }

        if (\str_replace(Text::get('regular-facebook-url'), '', $this->facebook) == '') {
            $this->facebook = '';
        }

        if (\str_replace(Text::get('regular-twitter-url'), '', $this->twitter) == '') {
            $this->twitter = '';
        }

        if (\str_replace(Text::get('regular-identica-url'), '', $this->identica) == '') {
            $this->identica = '';
        }

        if (\str_replace(Text::get('regular-linkedin-url'), '', $this->linkedin) == '') {
            $this->linkedin = '';
        }

        if (\str_replace(Text::get('regular-instagram-url'), '', $this->instagram) == '') {
            $this->instagram = '';
        }

        return (empty($errors['email']) && empty($errors['password']));
    }

    /**
     * Returns true if user is "unregistered":
     * ie: has no password, no social-login
     */
    public function isGhost() {
        // If is hide or inactive is also a ghost
        if(!$this->active || $this->hide) return true;
        $password = $this->getPassword();
        if(empty($password)) {
            // check social login
            $query = self::query('SELECT provider FROM user_login WHERE user = ?', array($this->id ? $this->id : $this->userid));
            if ($query->fetchColumn()) {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Changes, email (with database duplicate checks), password (Will be encoded) or Id (changing relationships between tables)
     * Throws ModelException on errors
     * @param  string $field [description]
     * @return this user (fluent)
     */
    public function rebase($value, $field = 'id') {
        $values = [':id' => $this->id];

        if($field === 'id') {
            if($value !== self::idealiza($value)) {
                throw new ModelException(Text::get('error-user-id-invalid'));
            }
            $query = self::query('SELECT id FROM user WHERE id = ?', $value);
            if ($found = $query->fetchColumn()) {
                if ($this->id !== $found) {
                    throw new ModelException(Text::get('error-user-id-exists'));
                }
            }

            $set = "`id` = :value ";
            $values[":value"] = $value;

        } elseif($field === 'password') {
            if (!Check::password($value)) {
                throw new ModelException(Text::get('error-user-password-invalid'));
            }

            $set = "`password` = :value ";
            $values[":value"] = Password::encode($value);

        } elseif($field === 'email') {
            if (!Check::mail($value)) {
                throw new ModelException(Text::get('error-user-email-invalid'));
            }

            $query = self::query('SELECT id FROM user WHERE email = ?', $value);
            if ($found = $query->fetchColumn()) {
                if ($this->id !== $found) {
                    throw new ModelException(Text::get('error-user-email-exists'));
                }
            }

            $set = "`email` = :value ";
            $values[":value"] = $value;

        } else {
            throw new ModelException("Field [$field] cannot be modified here, please use method ->save()");
        }

        $sql = "UPDATE user SET $set WHERE id = :id";
        // NOTE: Database should have now all relationships with user in foreign keys with update on cascade
        self::query($sql, $values);
        $this->{$field} = $values[':value'];

        return $this;
    }

    /**
     * Este método actualiza directamente los campos de email y contraseña de un usuario (para gestión de superadmin)
     * @deprecated
     */
    public function update(&$errors = array()) {
        if (!empty($this->password)) {
            if (!Check::password($this->password)) {
                $errors['password'] = Text::get('error-user-password-invalid');
            }
        }
        if (!empty($this->email)) {
            if (!Check::mail($this->email)) {
                $errors['email'] = Text::get('error-user-email-invalid');
            } else {
                $query = self::query('SELECT id FROM user WHERE email = ?', array($this->email));
                if ($found = $query->fetchColumn()) {
                    if ($this->id !== $found) {
                        $errors['email'] = Text::get('error-user-email-exists');
                    }
                }
            }
        }

        if (!empty($errors['email']) || !empty($errors['password'])) {
            return false;
        }

        $set = '';
        $values = array(':id' => $this->id);

        if (!empty($this->email)) {
            if ($set != '') {
                $set .= ", ";
            }

            $set .= "`email` = :email ";
            $values[":email"] = $this->email;
        }

        if (!empty($this->password)) {
            if ($set != '') {
                $set .= ", ";
            }

            $set .= "`password` = :password ";
            $values[":password"] = Password::encode($this->password);
        }

        if ($set == '') {
            return false;
        }

        try {
            $sql = "UPDATE user SET " . $set . " WHERE id = :id";
            self::query($sql, $values);

            return true;
        } catch (\PDOException $e) {
            $errors[] = "User update failed: " . $e->getMessage();
            return false;
        }

    }


    /**
     * Prepares/sets a new email on existing user
     */
    public function setEmail($email, &$errors = [], $update_email = false) {
        $query = self::query('SELECT email FROM user WHERE email = ?', $email);
        if ($query->fetchColumn() == $email) {
            $errors['email'] = Text::get('error-register-email-exists');
            return false;
        }

        if(!$update_email) {
            $this->setToken(md5(uniqid()) . '¬' . $email . '¬' . date('Y-m-d'));
            return true;
        }

        try{
            $values = [':id' => $this->id, ':email' => $email];
            $sql = "UPDATE user SET `email` = :email, `token` = '' WHERE id = :id";
            // die(\sqldbg($sql, $values));
            if(self::query($sql, $values)) {
                $this->email = $email;
                return true;
            }
        } catch (\PDOException $e) {
            $errors[] = "Error setting email" . $e->getMessage();
        }

        return false;
    }

    /**
     * This method changes the user password
     */
    public function setPassword($password, &$errors = [], $raw = false) {

        $values = array(':id' => $this->id);
        if($raw) {
            $values[":password"] = $password;
        } else {
            if (!empty($password)) {
                if (!Check::password($password)) {
                    $errors['password'] = Text::get('error-user-password-invalid');
                }
            }

            if (!empty($errors['password'])) {
                return false;
            }

            $values[":password"] = Password::encode($password);
        }

        try {
            $sql = "UPDATE user SET `password` = :password WHERE id = :id";
            // die(\sqldbg($sql, $values));
            if(self::query($sql, $values)) {
                if($this->password) $this->password = $password;
                return true;
            }

        } catch (\PDOException $e) {
            $errors[] = "Error setting password" . $e->getMessage();
        }

        return false;
    }

    /**
     * Returns all user langs
     * @return [type] [description]
     */
    static public function getAvailableLangs() {
        $query = self::query('SELECT DISTINCT lang FROM user UNION SELECT DISTINCT comlang AS lang FROM user_prefer');
        $langs = [];
        if ($query) {
            foreach ($query->fetchAll(\PDO::FETCH_OBJ) as $lang) {
                $langs[] = $lang->lang;
            }
        }
        return $langs;
    }

    /**
     * Este método actualiza directamente el campo de idioma preferido
     */
    public function updateLang($lang, &$errors = array()) {

        $values = array(':id' => $this->id, ':lang' => $lang);

        try {
            $sql = "UPDATE user SET `lang` = :lang WHERE id = :id";
            self::query($sql, $values);
            $this->lang = $lang;

            return true;
        } catch (\PDOException $e) {
            $errors[] = "Update lang user preferences failed! " . $e->getMessage();
        }
        return false;
    }

    /**
     * Este método actualiza directamente el campo de nodo
     */
    public function updateNode(&$errors = array()) {

        $values = array(':id' => $this->id, ':node' => $this->node);

        try {
            $sql = "UPDATE user SET `node` = :node WHERE id = :id";
            self::query($sql, $values);

            return true;
        } catch (\PDOException $e) {
            $errors[] = $e->getMessage();
            return false;
        }

    }

    /**
     * @param string $id user name
     * @return User|false
     * @throws Exception
     */
    public static function get($id, $lang = null, $with_password = false) {
        try {
            // This will ensure to have fallback translations in case $lang does not exists
            // However, I find more personal to let the user choose how to present himself
            // and handle his translations manually.
            // Still, I left it here commented in case of further discussion
            // Ivan Vergés  25/09/2017.
            // $lang = self::default_lang_by_id($id, 'user_lang', $lang);

            $sql = "
                SELECT
                    user.id as id,
                    IFNULL(user_lang.name, user.name) as name,
                    user.email as email,
                    user.active as active,
                    user.lang as lang,
                    user.location as location,
                    user.avatar as user_avatar,
                    " . ($with_password ? 'user.password as password,' : '') . "
                    IFNULL(user_lang.about, user.about) as about,
                    IFNULL(user_lang.contribution, user.contribution) as contribution,
                    IFNULL(user_lang.keywords, user.keywords) as keywords,
                    user.facebook as facebook,
                    user.twitter as twitter,
                    user.instagram as instagram,
                    user.identica as identica,
                    user.linkedin as linkedin,
                    user.amount as amount,
                    user.worth as worth,
                    user.confirmed as confirmed,
                    user.hide as hide,
                    user.created as created,
                    user.modified as modified,
                    user.node as node,
                    user.num_invested as num_invested,
                    user.num_owned as num_owned,
                    user.birthyear as birthyear,
                    user.entity_type as entity_type,
                    user.legal_entity as legal_entity,
                    user.gender as gender,
                    user.rememberme as rememberme
                FROM user
                LEFT JOIN user_lang
                    ON  user_lang.id = user.id
                    AND user_lang.lang = :lang
                WHERE user.id = :id
                ";

            $values = array(':id' => $id, ':lang' => $lang);
            // echo \sqldbg($sql, $values);
            $query = static::query($sql, $values);
            $user = $query->fetchObject(__CLASS__);

            if (!$user instanceof \Goteo\Model\User) {
                return false;
            }
            if (empty($user->lang)) {
                $user->lang = Lang::current();
            }

            $user->roles = $user->getLegacyRoles();
            $user->avatar = Image::get($user->user_avatar);
            $user->interests = UserInterest::get($id);

            // campo calculado tipo lista para las webs del usuario
            $user->webs = UserWeb::get($id);

            // Nodo
            if (!empty($user->node) && $user->node != \GOTEO_NODE) {
                $user->nodeData = Node::getMini($user->node);
            }

            // si es traductor cargamos sus idiomas
            if (isset($user->roles['translator'])) {
                $user->translangs = User\Translate::getLangs($user->id);
            }

            return $user;
        } catch (\PDOException $e) {
            return false;
        }
    }

    // version mini de get para sacar nombre, avatar, email, idioma y nodo
    public static function getMini($id) {
        try {
            $query = static::query("
                SELECT
                    id,
                    name,
                    avatar as user_avatar,
                    email,
                    lang,
                    node
                FROM user
                WHERE id = :id
                ", array(':id' => $id));

            $user = $query->fetchObject(); // stdClass para qno grabar accidentalmente y machacar todo

            if (!is_object($user)) {
                return false;
            }

            if (empty($user->lang)) {
                $user->lang = Lang::current();
            }

            $user->avatar = Image::get($user->user_avatar);

            return $user;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Lista de usuarios.
     *
     * @param  array $filters  Filtros
     * @param  string|array $subnodes Filtra además por nodo o nodos (si es un array),
     *                                también si el usuario ha invertido en un proyecto de ese nodo
     * @return mixed            Array de objetos de usuario activos|todos.
     */
    public static function getList($filters = array(), $subnodes = null, $offset = 0, $limit = 100, $count = false) {

        $values = array();

        $users = array();

        // ?? NO root
        $sqlFilter = [];
        $sqlOrder = [];
        $sqlCR = [];
        $sqlCR2 = [];

        if (Session::getUserId() != 'root') {
            $sqlFilter[] = "id != 'root'";
        }

        if (!empty($filters['id'])) {
            $sqlFilter[] = "id = :id";
            $values[':id'] = $filters['id'];
        }
        if (!empty($filters['global'])) {
            $sqlFilter[] = "(id LIKE :global OR name LIKE :global OR email LIKE :global)";
            $values[':global'] = '%' . $filters['global'] . '%';
            $sqlOrder[] = " id = :oglobal DESC";
            $sqlOrder[] = " email = :oglobal DESC";
            $sqlOrder[] = " name = :oglobal DESC";
            $values[':oglobal'] = $filters['global'];
        }
        if (!empty($filters['superglobal'])) {
            $sqlFilter[] = "(id LIKE :superglobal OR name LIKE :superglobal OR email LIKE :superglobal OR
                             (SELECT CONCAT(GROUP_CONCAT(id), '', GROUP_CONCAT(name)) FROM project WHERE project.owner=user.id) LIKE :superglobal)";
            $values[':superglobal'] = '%' . $filters['superglobal'] . '%';
        }
        if (!empty($filters['name'])) {
            $sqlFilter[] = "(id LIKE :name OR name LIKE :name)";
            $values[':name'] = '%' . $filters['name'] . '%';
        }
        if (!empty($filters['status'])) {
            $sqlFilter[] = "active = :active";
            $values[':active'] = $filters['status'] == 'active' ? '1' : '0';
        }
        if (!empty($filters['interest'])) {
            $sqlFilter[] = "id IN (
                SELECT user
                FROM user_interest
                WHERE interest = :interest
                ) ";
            $values[':interest'] = $filters['interest'];
        }
        if (!empty($filters['role']) && $filters['role'] != 'user') {
            $sqlFilter[] = "id IN (
                SELECT user_id
                FROM user_role
                WHERE role_id = :role
                ) ";
            $values[':role'] = $filters['role'];
        }
        // Has or not has money in the pool
        if (isset($filters['pool'])) {
            $sqlFilter[] = 'id IN (SELECT `user` FROM user_pool WHERE user_pool.amount ' . ($filters['pool'] ? '>'  : '=') .' 0)';
        }


        // un admin de central puede filtrar usuarios de nodo
        if ($subnodes) {
            if (!is_array($subnodes)) {
                $subnodes = array((string) $subnodes);
            }

            $ns = array();
            foreach ($subnodes as $i => $node) {
                $ns[":node$i"] = $node;
                $values[":node$i"] = $node;
            }
            $sqlFilter[] = "(node IN (" . implode(', ', array_keys($ns)) . ")
                OR id IN (
                    SELECT user_id
                    FROM invest_node
                    WHERE project_node IN (" . implode(', ', array_keys($ns)) . ")
                )
            )";
        }
        if (!empty($filters['node'])) {
            $sqlFilter[] = "node = :node";
            $values[':node'] = $filters['node'];
        }

        if (!empty($filters['project'])) {
            $subFilter = $filters['project'] == 'any' ? '' : 'invest.project = :project AND';
            $sqlFilter[] = "id IN (
                SELECT user
                FROM invest
                WHERE {$subFilter} invest.status IN ('0', '1', '3', '4')
                ) ";
            if ($filters['project'] != 'any') {
                $values[':project'] = $filters['project'];
            }
        }

        // por tipo de usuario (un usuario puede ser de más de un tipo)
        if (!empty($filters['type'])) {
            switch ($filters['type']) {
            case 'creators': // crean proyectos que se publican
                $sqlFilter[] = " id IN (
                        SELECT DISTINCT(owner)
                        FROM project
                        WHERE status > 2
                        ) ";
                break;
            case 'investors': // aportan correctamente a proyectos
                $sqlFilter[] = " id IN (
                        SELECT DISTINCT(user)
                        FROM invest
                        WHERE status IN ('0', '1', '3', '4')
                        ) ";
                break;
            case 'supporters': // colaboran con el proyecto
                $sqlFilter[] = " id IN (
                        SELECT DISTINCT(user)
                        FROM message
                        WHERE thread IN (
                            SELECT id
                            FROM message
                            WHERE thread IS NULL
                            AND blocked = 1
                            )
                        ) ";
                break;
            case 'consultants': // asesores de proyectos (admins o consultants)
                $sqlFilter[] = " id IN (SELECT DISTINCT(user) FROM user_project) ";
                $sqlCR[] = "(SELECT COUNT(project) FROM user_project JOIN project ON user_project.project=project.id AND project.published>DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 YEAR) WHERE user_project.user=user.id GROUP BY `user`) AS total_consultant";
                $sqlOrder[] = 'total_consultant DESC';
                break;
            case 'lurkers': // colaboran con el proyecto
                $sqlFilter[] = " id NOT IN (
                            SELECT DISTINCT(user)
                            FROM invest
                            WHERE status IN ('0', '1', '3', '4')
                        )
                         AND id NOT IN (
                            SELECT DISTINCT(user)
                            FROM invest
                            WHERE status IN ('0', '1', '3', '4')
                        )
                         AND id NOT IN (
                            SELECT DISTINCT(user)
                            FROM message
                        )
                        ";
                break;
            }
        }

        // si es solo los usuarios normales, añadimos HAVING
        // TODO: cambiar esto a JOINS
        if ($filters['role'] == 'user') {
            $sqlCR[] = "(SELECT COUNT(role_id) FROM user_role WHERE user_id = user.id) as roles";
            $sqlCR2[] = " HAVING roles = 0";
        }

        //el Order
        switch ($filters['order']) {
            case 'name':
                $sqlOrder[] = "name ASC";
                break;
            case 'id':
                $sqlOrder[] = "id ASC";
                break;
            default:
                $sqlOrder[] = "created DESC";
        }
        if($sqlFilter) $sqlFilter = 'WHERE '. implode(' AND ', $sqlFilter);
        else  $sqlFilter = '';

        $sqlCR = implode(',', $sqlCR);
        if($sqlCR) $sqlCR = ",$sqlCR";
        $sqlCR2 = implode(' ', $sqlCR2);

        if ($count) {
            // Return count
            $sql = "SELECT COUNT(id) as total FROM user $sqlFilter";
            return (int) self::query($sql, $values)->fetchColumn();
        }

        if($sqlOrder) $sqlOrder = " ORDER BY " . implode(',', $sqlOrder);

        $offset = (int) $offset;
        $limit = (int) $limit;
        $sql = "SELECT
                    user.*
                    $sqlCR
                FROM user
                $sqlFilter
                $sqlCR2
                $sqlOrder
                LIMIT $offset, $limit
                ";
        // die(\sqldbg($sql, $values));
        // echo str_replace(array_keys($values), array_values($values),$sql).'<br />';
        $query = self::query($sql, $values);

        foreach ($query->fetchAll(\PDO::FETCH_CLASS, __CLASS__) as $user) {
            $users[] = $user;
        }
        return $users;
    }

    /*
                 * Listado simple de todos los usuarios
    */
    public static function getAllMini() {

        $list = array();

        $query = static::query("
            SELECT
                user.id as id,
                CONCAT(user.name, ' (', user.email, ')') as name
            FROM    user
            ");

        foreach ($query->fetchAll(\PDO::FETCH_CLASS) as $item) {
            $list[$item->id] = $item->name;
        }

        return $list;
    }

    /*
                 * Listado simple de los usuarios que han creado proyectos
    */
    public static function getOwners() {

        $list = array();

        $query = static::query("
            SELECT
                user.id as id,
                user.name as name
            FROM    user
            INNER JOIN project
                ON project.owner = user.id
            ORDER BY user.name ASC
            ");

        foreach ($query->fetchAll(\PDO::FETCH_CLASS) as $item) {
            $list[$item->id] = $item->name;
        }

        return $list;
    }

    /*
                 * Consulta simple de si el usuario es impulsor (de proyecto publicado)
    */
    public static function isOwner($user, $published = false, $dbg = false) {

        $sql = "SELECT COUNT(*) FROM project WHERE owner = ?";
        if ($published) {
            $sql .= " AND status > 2";
        }
        $sql .= " ORDER BY created DESC";
        if ($dbg) {
            echo $sql . \trace($user) . '<br />';
        }

        $query = self::query($sql, array($user));
        $is = $query->fetchColumn();
        if ($dbg) {
            var_dump($is);
        }

        return !empty($is);
    }

    /*
                 * Listado simple de los usuarios Convocadores
    */
    public static function getCallers() {

        $list = array();

        $query = static::query("
            SELECT
                user.id as id,
                user.name as name
            FROM    user
            INNER JOIN user_role
                ON  user_role.user_id = user.id
                AND user_role.role_id = 'caller'
            ORDER BY user.name ASC
            ");

        foreach ($query->fetchAll(\PDO::FETCH_CLASS) as $item) {
            $list[$item->id] = $item->name;
        }

        return $list;
    }

    /*
                 * Listado simple de los usuarios Administradores
                 * @param boolean $availableonly si es true, solo devuelve los administradores que no tienen asignado ningún nodo
    */
    public static function getAdmins($availableonly = false) {

        $list = array();

        $sql = "
            SELECT
                user.id as id,
                user.name as name
            FROM    user
            INNER JOIN user_role
                ON  user_role.user_id = user.id
                AND user_role.role_id IN ('admin', 'superadmin')
            ";

        if ($availableonly) {
            $sql .= " WHERE ISNULL(user_role.node_id)";
        }

        $sql .= " ORDER BY user.name ASC
            ";

        $query = static::query($sql);

        foreach ($query->fetchAll(\PDO::FETCH_CLASS) as $item) {
            $list[$item->id] = $item->name;
        }

        return $list;
    }

    /*
                 * Listado simple de los usuarios Colaboradores
    */
    public static function getVips() {

        $list = array();

        $query = static::query("
            SELECT
                user.id as id,
                user.name as name
            FROM    user
            INNER JOIN user_role
                ON  user_role.user_id = user.id
                AND user_role.role_id = 'vip'
            ORDER BY user.name ASC
            ");

        foreach ($query->fetchAll(\PDO::FETCH_CLASS) as $item) {
            $list[$item->id] = $item->name;
        }

        return $list;
    }

    /**
     * Get a user by mail
     *
     * @param string $username Nombre de usuario
     * @param string $password Contraseña
     * @return obj|false Objeto del usuario, en caso contrario devolverá 'false'.
     */
    public static function getByEmail($email, $lang = null, $with_password = false) {

        $query = self::query("SELECT id FROM user WHERE email = ?", $email);

        if ($row = $query->fetch()) {
            if ($user = static::get($row['id'], $lang, $with_password)) {
                return $user;
            }
        }
        return false;
    }

    /**
     * Validación de usuario.
     *
     * @param string $username Nombre de usuario
     * @param string $password Contraseña
     * @return obj|false Objeto del usuario, en caso contrario devolverá 'false'.
     */
    public static function login($username, $password, $allow_login_by_mail = true) {

        if ($allow_login_by_mail && strpos($username, '@') !== false) {
            $user = self::getByEmail($username, null, false);
        } else {
            $user = self::get($username, null, false);
        }

        if ($user) {
            $pass = new Password($user->getPassword());
            if(!$pass->isPasswordValid($password)) {
                return false;
            }

            // Re-encode password and save it to database if it's considered non-secure
            if(!$pass->isSecure()) {
                $errors = [];
                $user->setPassword(Password::encode($password), $errors, true);
            }

            if ($user->active) {
                // ponemos su divisa preferida en sesión
                $prefs = self::getPreferences($row['id']);
                if (!empty($prefs->currency)) {
                    Session::store('currency', $prefs->currency);
                }

                return $user;
            } else {
                Application\Message::error(Text::get('user-account-inactive'));
            }
        }
        return false;
    }

    /**
     * Returns the current password for the user
     * @return [type] [description]
     */
    public function getPassword() {
        if($this->password) return $this->password;
        $query = self::query('SELECT password FROM user WHERE id = :id', [':id' => $this->id ? $this->id : $this->userid]);
        $this->password = $query->fetchColumn();
        return $this->password;
    }

    /**
     * Checks if a password is valid for the user
     */
    public function validatePassword($password) {
        $pass = new Password($this->getPassword());
        return $pass->isPasswordValid($password);
    }

    /**
     * Returns the current pool for the user
     * @return [type] [description]
     */
    public function getPool() {
        // if($this->poolInstance) return $this->poolInstance;
        $this->poolInstance = UserPool::get($this);
        return $this->poolInstance;
    }

    /**
     * Returns the object UserRoles for this user
     * @return [type] [description]
     */
    public function getRoles() {
        if($this->rolesInstance) return $this->rolesInstance;
        $this->rolesInstance = UserRoles::getRolesForUser($this);
        return $this->rolesInstance;
    }


    /**
     * Returns project names owned by the user
     * @return [type] [description]
     */
    public function getProjectNames($limit = 10) {
        $limit = (int)$limit;
        $sql = "SELECT p.id, p.name, p.image FROM project p WHERE p.owner = ? ORDER BY p.name ASC, p.updated DESC, p.created DESC LIMIT $limit";
        $query = self::query($sql, $this->id);
        $projects = [];
        foreach ($query->fetchAll(\PDO::FETCH_OBJ) as $p) {
            $t = '';
            if($p->image) $t = '<img src="' . Image::get($p->image)->getLink(64, 64, true). '" class="img-circle"> ';
            $t .= $p->name;
            $projects[$p->id] = $t;
        }
        return $projects;
    }

    public function getTotalProjects() {
        $sql = "SELECT COUNT(*) as total FROM project p WHERE p.owner = ?";
        return (int) self::query($sql, $this->id)->fetchColumn();
    }

    public function getTotalPublishedProjects() {
        $sql = "SELECT COUNT(*) as total FROM project p WHERE p.owner = ? AND p.status IN (3,4,5,6)";
        return (int) self::query($sql, $this->id)->fetchColumn();
    }

    public function getInvests($limit = 10) {
        return Invest::getList(['status' => Invest::$RAISED_STATUSES, 'users' => $this->id], null, 0, $limit);
    }

    public function getTotalInvests() {
        return Invest::getList(['users' => $this->id], null, 0, 0, true);
    }

    public function getMailing($limit = 10) {
        return Mail::getSentList(['email' => $this->email], 0, $limit);
    }
    public function getTotalMailing() {
        return Mail::getSentList(['email' => $this->email], 0, 0, true);
    }

    /**
     * shortcut for UserRoles::hasPerm
     */
    public function hasPerm($perm, $model_val = null) {
        return $this->getRoles()->hasPerm($perm, $model_val);
    }

    /**
     * shortcut for UserRoles::hasRole
     */
    public function hasRole($role) {
        return $this->getRoles()->hasRole($role);
    }

    /**
     * Return all the user roles
     */
    public function getLegacyRoles() {

        $roles = array();
        $query = self::query('
            SELECT
                role.id as id,
                role.name as name
            FROM role
            JOIN user_role ON role.id = user_role.role_id
            WHERE user_id = ? ', array($this->id));
        foreach ($query->fetchAll(\PDO::FETCH_OBJ) as $rol) {
            $roles[$rol->id] = $rol;
        }
        // añadimos el de usuario normal
        $roles['user'] = (object) array('id' => 'user', 'name' => 'Usuario registrado');

        return $roles;

    }

    /**
     * Returns a list of roles
     * @param  string $administrable_by_role specify a role to get only the roles administrable by that role
     * @return array                        roles
     */
    public static function getRolesList($administrable_by_role = 'root') {

        $roles = array();
        if ($administrable_by_role === 'root') {
            $filter = '';
        } elseif ($administrable_by_role === 'superadmin') {
            $filter = "WHERE role.id != 'root'";
        } elseif ($administrable_by_role === 'admin') {
            $filter = "WHERE role.id NOT IN ('superadmin', 'root')";
        } else {
            return $roles;
        }

        $query = self::query('SELECT role.id as id, role.name as name FROM role ' . $filter . ' ORDER BY role.name');
        foreach ($query->fetchAll(\PDO::FETCH_OBJ) as $rol) {
            $roles[$rol->id] = $rol->name;
        }
        return $roles;

    }

    /**
     * Returns the highest current role in node
     */
    public function getNodeRole($node) {
        $roles = $this->getAllNodeRoles();
        if (array_key_exists($node, $roles) && $roles[$node]) {
            return $roles[$node][0];
        }
        return false;
    }

    /**
     * Returns if a user has certain role in node
     * By default checks if its a kind of admin
     * @param  string  $node  the node to check
     * @param  array|string   $roles if is an array check if has some of the roles specified
     *                               if is a string, checks that role only
     * @return boolean        return true if has role
     */
    public function hasRoleInNode($node, $check_roles = array('manager', 'admin', 'superadmin', 'root')) {
        if (!is_array($check_roles)) {
            $check_roles = [(string) $check_roles];
        }
        foreach ($this->getAllNodeRoles() as $n => $roles) {
            if ($node === $n && array_intersect($roles, $check_roles)) {
                return true;
            }
        }
        return false;
    }

    public function getNodeNames() {
        // all node names
        $all_nodes = $this->getAdminNodes();
        // simple list of administrable nodes
        $admin_nodes = array();
        foreach (Node::getList() as $node_id => $name) {
            $admin_nodes[$node_id] = $name;
        }
        return $admin_nodes;
    }

    /**
     * Returns all nodes administrable by the user along with the highest role in that node
     * @return array Array of nodes containing a simple object of node, name, role property
     */
    public function getAdminNodes() {
        $admin_nodes = array();
        foreach ($this->getAllNodeRoles() as $node => $roles) {
            $role = '';
            if (in_array($roles[0], array('root', 'superadmin', 'admin', 'manager'))) {
                $admin_nodes[$node] = $roles[0];
            }
        }
        // print_r($admin_nodes);die;
        return $admin_nodes;
    }

    /**
     * Returns an array of all nodes and the roles in each one for the user
     * @return array Array of nodes containing a simple object of node, name, role property
     */
    public function getAllNodeRoles() {
        if (is_array($this->all_roles_nodes)) {
            return $this->all_roles_nodes;
        }

        $this->all_roles_nodes = array();

        $query = self::query('
            SELECT DISTINCT
                user_role.node_id AS `node`,
                user_role.role_id AS `role`
            FROM user_role
            WHERE
                user_role.user_id = ?
            ', array($this->id));

        if ($query) {
            foreach ($query->fetchAll(\PDO::FETCH_OBJ) as $n) {
                $role = $n->role;
                $node = $n->node;

                if (!array_key_exists($node, $this->all_roles_nodes)) {
                    $this->all_roles_nodes[$node] = array();
                }
                if (in_array($role, $this->all_roles_nodes[$node])) {
                    continue;
                }
                $this->all_roles_nodes[$node][] = $role;
            }
            // Add all nodes if empty node specified
            if (array_key_exists('', $this->all_roles_nodes)) {
                //assign all nodes if node_id not specified
                $query = self::query('SELECT node.id AS `node` FROM node');
                $roles = $this->all_roles_nodes[''];
                foreach ($query->fetchAll(\PDO::FETCH_OBJ) as $m) {
                    if (!array_key_exists($m->node, $this->all_roles_nodes)) {
                        $this->all_roles_nodes[$m->node] = $roles;
                    }
                }
                unset($this->all_roles_nodes['']);
            }
            // Order array
            foreach ($this->all_roles_nodes as $node => $roles) {
                usort($this->all_roles_nodes[$node], function ($a, $b) {
                    $order = array('root' => 0, 'superadmin' => 1, 'admin' => 2, 'caller' => 3, 'translator' => 4, 'checker' => 5, 'manager' => 6);
                    if (!isset($order[$a])) {
                        return 1;
                    }

                    return ($order[$a] < $order[$b]) ? -1 : 1;
                });
            }
        }
        // print_r($this->all_roles_nodes);die;
        return $this->all_roles_nodes;
    }
    /**
     * Returns the list of roles without sugar
     * ie: if non node is specified for a role, the list will not be completed
     * @return [type] [description]
     */
    public function getAllNodeRolesRaw($only_roles = array()) {
        $all_roles_nodes_raw = array();
        $filter = '';
        if (!is_array($only_roles)) {
            $only_roles = array($only_roles);
        }

        $values = array(':id' => $this->id);
        if ($only_roles) {
            foreach ($only_roles as $i => $role) {
                $values[":role$i"] = $role;
            }
            $filter = " AND user_role.role_id IN (" . implode(', ', array_keys($values)) . ")";
        }

        $query = self::query('SELECT
            role_id AS role,
            node_id AS node
            FROM user_role
            WHERE
                user_role.user_id = :id
                ' . $filter .
            ' ORDER BY user_role.node_id ASC', $values);

        if ($query) {
            foreach ($query->fetchAll(\PDO::FETCH_OBJ) as $rol) {
                $node = (string) $rol->node;
                if (!is_array($all_roles_nodes_raw[$node])) {
                    $all_roles_nodes_raw[$node] = array();
                }
                $all_roles_nodes_raw[$node][] = $rol->role;
            }
            // Order array
            foreach ($all_roles_nodes_raw as $node => $roles) {
                usort($all_roles_nodes_raw[$node], function ($a, $b) {
                    $order = array('root' => 0, 'superadmin' => 1, 'admin' => 2, 'caller' => 3, 'translator' => 4, 'checker' => 5, 'manager' => 6);
                    if (!isset($order[$a])) {
                        return 1;
                    }

                    return ($order[$a] < $order[$b]) ? -1 : 1;
                });
            }
        }
        // print_r($all_roles_nodes_raw);die;
        return $all_roles_nodes_raw;
    }

    /**
     * Checks if this user can impersonate another
     * @param  User   $user [description]
     * @return [type]       [description]
     */
    public function canImpersonate(User $user) {
        if($this->hasPerm('impersonate-everyone')) return true;
        if(!$user->hasRole(['admin', 'superadmin', 'root']) && $this->hasPerm('impersonate-owners', $user->id)) return true;
        // Admins cannot impersonate other admins
        if(!$user->hasRole(['admin', 'superadmin', 'root']) && $this->hasPerm('impersonate-users', 'admin')) return true;
        // Can superadmins impersonate other superadmins?
        // Case Yes
        if(!$user->hasRole('root') && $this->hasPerm('impersonate-users', 'superadmin')) return true;
        // Case No
        // if(!$user->hasRole(['superadmin', 'root']) && $this->hasPerm('impersonate-users', 'superadmin')) return true;
        if(!$user->hasRole('root') && $this->hasPerm('impersonate-users', 'root')) return true;

        return false;
    }

    /**
     * Checks if this user can edit sensitive data from another user
     * @param  User   $user [description]
     * @return [type]       [description]
     */
    public function canRebase(User $user) {
        // Admins cannot impersonate other admins
        if(!$user->hasRole(['admin', 'superadmin', 'root']) && $this->hasPerm('rebase-users', 'admin')) return true;
        // Can superadmins rebase other superadmins?
        // Case Yes
        if(!$user->hasRole('root') && $this->hasPerm('rebase-users', 'superadmin')) return true;
        // Case No
        // if(!$user->hasRole(['superadmin', 'root']) && $this->hasPerm('rebase-users', 'superadmin')) return true;
        if(!$user->hasRole('root') && $this->hasPerm('rebase-users', 'root')) return true;

        return false;
    }


    /**
     * Checks if this user can change the role of another user
     * @param  User   $user [description]
     * @return [type]       [description]
     */
    public function canChangeRole($roles, &$failed = '') {
        if(!is_array($roles)) $roles = [$roles];
        $my_roles = $this->getRoles();
        // User Role has to be at least admin
        if(!$my_roles->atLeast('admin')) {
            $failed = 'admin';
            return false;
        }

        foreach($roles as $role) {
            if($my_roles->atLeast('superadmin')) {
                if(!$my_roles->atLeast($role)) {
                    $failed = $role;
                    return false;
                }
            } else {
                if(!$my_roles->greaterThan($role)) {
                    $failed = $role;
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Checks if current user can admin some role on some node
     * if node is empty, all nodes permission assumed
     * @param  [type] $to_role [description]
     * @param  string $to_node [description]
     * @return [type]          [description]
     */
    public function canAdminRoleInNode($to_role, $to_node = '') {

        foreach ($this->getAllNodeRolesRaw(['root', 'superadmin', 'admin']) as $node => $roles) {
            if (in_array('root', $roles)) {
                $non_administrable_roles = [];
            } elseif (in_array('superadmin', $roles)) {
                $non_administrable_roles = ['root'];
            } else {
                $non_administrable_roles = ['superadmin', 'root'];
            }

            // echo "<br>[role '$role' in '$node'] againts [role '$to_role' in '$to_node']";
            if (($node === $to_node || $node === '') && !in_array($to_role, $non_administrable_roles)) {
                // echo " OK [role '$to_role' in '$to_node']\n";
                return true;
            }
        }

        return false;
    }

    public function delRoleFromNode($to_role, $to_node = '') {
        $values = array(':user' => $this->id, ':role' => $to_role);
        $where = 'WHERE user_id = :user AND role_id = :role';
        if ($to_node) {
            $where .= ' AND node_id = :node';
            $values[':node'] = $to_node;
        } else {
            $where .= ' AND ISNULL(node_id)';
        }
        self::query('DELETE FROM user_role ' . $where, $values);

        return 0 === (int) self::query('SELECT COUNT(*) FROM user_role ' . $where, $values)->fetchColumn();
    }

    public function addRoleToNode($to_role, $to_node = '') {
        $values = array(':user' => $this->id, ':role' => $to_role);
        $insert_sql = 'INSERT INTO user_role (user_id,role_id,node_id) VALUES (:user, :role, ';
        if ($to_node) {
            $insert_sql .= ':node)';
            $values[':node'] = $to_node;
        } else {
            $insert_sql .= 'NULL)';
        }
        if ($this->delRoleFromNode($to_role, $to_node)) {
            if (self::query($insert_sql, $values)) {
                return true;
            }

        }
        return false;
    }
    /**
     * Refresca la sesión.
     * (Utilizar después de un save)
     *
     * @return type object  User
     */
    public static function flush() {
        if ($id = Session::getUserId()) {
            return Session::setUser(self::get($id));
        }
    }

    /**
     * Verificacion de recuperacion de contraseña
     *
     * @param string $username Nombre de usuario
     * @param string $email    Email de la cuenta
     * @return boolean true|false  Correctos y mail enviado
     */
    public static function recover($email = null, $allow_recover_by_id = true) {
        $URL = \SITE_URL;
        $field = 'email';
        if ($allow_recover_by_id && strpos($email, '@') === false) {
            $field = 'id';
        }

        $query = self::query("
                SELECT * FROM user
                WHERE $field = :email
                ",
            array(
                ':email' => trim($email),
            )
        );

        if ($row = $query->fetchObject(__CLASS__)) {
            // tenemos id, nombre, email
            // genero el token
            $token = md5(uniqid()) . '¬' . $row->email . '¬' . date('Y-m-d');
            // Obtener el token antiguo si existe
            if ($row->token && strpos($row->token, '¬') !== false) {
                $t = explode('¬', $row->token);

                //si el token actual no es muy antiguo (4 dias) lo reeusamos
                if ($t[2] && strtotime($t[2]) + (3600 * 24 * 4) > time()) {
                    $token = $row->token;
                }
            }
            // die("[$token] [" . \mybase64_encode($token). "]");
            if (self::query('UPDATE user SET token = :token WHERE id = :id', array(':id' => $row->id, ':token' => $token))) {
                $row->token = $token;

                return $row;
            }

        }
        return false;
    }

    /**
     * Verificacion de darse de baja
     *
     * @param string $email    Email de la cuenta
     * @return boolean true|false  Correctos y mail enviado
     */
    public static function leaving($email, $message = null) {
        $URL = \SITE_URL;
        $query = self::query("
                SELECT
                    id,
                    name,
                    email
                FROM user
                WHERE email = :email
                ",
            array(
                ':email' => trim($email),
            )
        );
        if ($row = $query->fetchObject()) {
            // tenemos id, nombre, email
            // genero el token
            $token = md5(uniqid()) . '¬' . $row->email . '¬' . date('Y-m-d');
            self::query('UPDATE user SET token = :token WHERE id = :id', array(':id' => $row->id, ':token' => $token));

            // Obtenemos la plantilla para asunto y contenido
            $template = Template::get(Template::UNSUBSCRIBE, $comlang);

            // Sustituimos los datos
            $subject = $template->title;
            $errors = [];
            // En el contenido:
            $search = array('%USERNAME%', '%URL%');
            $replace = array($row->name, SITE_URL . '/user/leave/' . \mybase64_encode($token));
            $content = \str_replace($search, $replace, $template->parseText());
            // Email de recuperacion
            $mail = new Mail();
            $mail->lang = $comlang;
            $mail->to = $row->email;
            $mail->toName = $row->name;
            $mail->subject = $subject;
            $mail->content = $content;
            $mail->html = true;
            $mail->template = $template->id;
            $mail->send($errors);
            unset($mail);

            // email to the platform
            $template_user_leaves = Template::get(Template::USER_LEAVES_PLATFORM, Config::get('lang'));
            $search = array('%USERNAME%','%USEREMAIL%', '%MESSAGE%');
            $replace = array($row->name, $row->email, $message);
            $content_user_leaves = \str_replace($search, $replace, $template_user_leaves->text);

            $mail = new Mail();
            $mail->to = Config::getMail('mail');
            $mail->lang = Config::get('lang');
            $mail->toName = 'Admin Goteo';
            $mail->subject = $template->title;
            $mail->content = $content_user_leaves;
            $mail->fromName = "{$row->name}";
            $mail->from = $row->email;
            $mail->html = true;
            $mail->template = $template_user_leaves->id;
            $mail->send($errors);
            unset($mail);


            return true;
        }
        return false;
    }

    /**
     * Guarda el Token y envía un correo de confirmación.
     *
     * Usa el separador: ¬
     *
     * @param type string   $token  Formato: '<md5>¬<email>'
     * @return type bool
     */
    private function setToken($token, &$errors = []) {
        $URL = \SITE_URL;
        if (count($tmp = explode('¬', $token)) > 1) {
            $email = $tmp[1];
            if (Check::mail($email)) {

                if (Mail::createFromTemplate($email, $this->name, Template::EMAIL_CHANGE, [
                    '%USERNAME%' => $this->name,
                    '%CHANGEURL%' => \SITE_URL . '/user/changeemail/' . \mybase64_encode($token),
                ])->send($errors)) {
                    if (self::query('UPDATE user SET token = :token WHERE id = :id', array(':id' => $this->id, ':token' => $token))) {
                        $this->token = $token;
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Token de confirmación.
     *
     * @return type string
     */
    public function getToken($email_only = false) {
        if (!$this->token) {
            $query = self::query('SELECT token FROM user WHERE id = ?', array($this->id));
            $this->token = $query->fetchColumn();
        }

        if($email_only) {
            if (count($tmp = explode('¬', $this->token)) > 1) {
                return $tmp[1];
            }
            return false;
        }

        return $this->token;
    }

    /**
     * Returns the user's location
     * @return UserLocation if succeded, false otherwise
     */
    public function getLocation() {
        return UserLocation::get($this->id);
	}

	/**
	 * Return if a project is favourite for a user
	 * @return True if is favoruite false otherwise
	 */
	public function isFavouriteProject($project) {
		return Favourite::isFavouriteProject($project, $this->id);
    }


    /**
     * Cofinanciación.
     *
     * @return type array
     */
    private function getSupport() {
        $query = self::query("SELECT DISTINCT(project) FROM invest WHERE user = ? AND status IN ('0', '1', '3')", array($this->id));
        $projects = $query->fetchAll(\PDO::FETCH_ASSOC);
        $query = self::query("SELECT SUM(amount), COUNT(id) FROM invest WHERE user = ? AND status IN ('0', '1', '3')", array($this->id));
        $invest = $query->fetch();
        return array('projects' => $projects, 'amount' => $invest[0], 'invests' => $invest[1]);
    }

    /*
                 * Método para calcular el número de proyectos cofinanciados
                 * Actualiza el campo
    */
    public static function numInvested($id) {
        $query = self::query("SELECT num_invested as old_num_invested, (SELECT COUNT(DISTINCT(project)) FROM invest WHERE user = :user AND status IN ('0', '1', '3', '4')) as num_invested FROM user WHERE id = :user", array(':user' => $id));
        $inv = $query->fetchObject();
        if ($inv->old_num_invested != $inv->num_invested) {
            self::query("UPDATE
                    user SET
                    num_invested = :nproj
                 WHERE id = :id", array(':id' => $id, ':nproj' => $inv->num_invested));
        }
        return $inv->num_invested;
    }

    /**
     * Recalcula y actualiza el nivel de meritocracia
     * Segun el actual importe cofinanciado por el usuario
     *
     * @param $amount int
     * @return result boolean
     */
    public static function updateWorth($user, $amount) {
        $query = self::query('SELECT worth as old_worth, (SELECT id FROM worthcracy WHERE amount <= :amount ORDER BY amount DESC LIMIT 1) as new_worth FROM user WHERE id = :user', array(':amount' => $amount, ':user' => $user));
        $usr = $query->fetchObject();
        if ($usr->old_worth != $usr->new_worth) {
            self::query('UPDATE user SET worth = :worth WHERE id = :id', array(':id' => $user, ':worth' => $usr->new_worth));
        }
        return $usr->new_worth;
    }

    /**
     * Número de proyectos publicados
     *
     * @return type int Count(id)
     */
    public static function updateOwned($user) {
        $query = self::query('SELECT num_owned as old_num, (SELECT COUNT(id) FROM project WHERE owner = :user AND status > 2) as new_num FROM user WHERE id = :user', array(':user' => $user));
        $num = $query->fetchObject();
        if ($num->old_num != $num->new_num) {
            self::query('UPDATE user SET num_owned = :num WHERE id = :id', array(':id' => $user, ':num' => $num->new_num));
        }
        return $num->new_num;
    }

    /**
     * Actualiza Cantidad aportada
     *
     * @param user string Id del usuario
     * @return type int Count(id)
     */
    public static function updateAmount($user) {
        $query = self::query("SELECT amount as old_amount, (SELECT SUM(invest.amount) FROM invest WHERE user = :user AND status IN ('0', '1', '3')) as new_amount FROM user WHERE id = :user", array(':user' => $user));
        $amount = $query->fetchObject();
        if ($amount->old_amount != $amount->new_amount) {
            self::query('UPDATE user SET amount = :amount WHERE id = :id', array(':id' => $user, ':amount' => $amount->new_amount));
        }
        return $amount->new_amount;
    }

    /**
     * Valores por defecto actuales para datos personales
     *
     * @return type array
     */
    public static function getPersonal($user) {
        if($user instanceOf User) $user = $user->id;

        $query = self::query('SELECT
                                  contract_name,
                                  contract_name AS name,
                                  contract_nif,
                                  contract_nif AS nif,
                                  contract_legal_document_type,
                                  contract_legal_document_type as legal_document_type,
                                  phone,
                                  address,
                                  zipcode,
                                  location,
                                  country
                              FROM user_personal
                              WHERE user = ?'
            , array($user));

        $data = $query->fetchObject();

        // UNCOMMENT WHEN VIEWS READY
        // Old manual country name
        // if(strlen($data->country) > 2) {
        //     $data->country = Lang::getCountryCode($data->country);
        // }

        return $data;
    }

    /**
     * Actualizar los valores personales
     *
     * @params force boolean  (REPLACE data when true, only if empty when false)
     * @return type booblean
     */
    public static function setPersonal($user, $data = array(), $force = false, &$errors = array()) {
        if($user instanceOf User) $user = $user->id;

        if ($force) {
            // actualizamos los datos
            $ins = 'REPLACE';
        } else {
            // solo si no existe el registro
            $ins = 'INSERT';
            $query = self::query('SELECT user FROM user_personal WHERE user = ?', array($user));
            if ($query->fetchColumn(0) == $user) {
                return false;
            }
        }

        //TODO Validate
        //TODO Create class for user_personal
        $fields = array(
            'contract_name',
            'contract_nif',
            'contract_legal_document_type',
            'phone',
            'address',
            'zipcode',
            'location',
            'country',
        );

        $values = array();
        $insert = [];
        foreach($fields as $key) {
            if(isset($data[$key])) {
                $insert[] = "$key = :$key";
                $values[":$key"] = $data[$key];
            }
        }

        if ($values) {
            $values[':user'] = $user;
            $sql = "$ins INTO user_personal SET user = :user, " . implode(',', $insert);
            // die(\sqldbg($sql, $values));
            try {
                self::query($sql, $values);
                return true;

            } catch (\PDOException $e) {
                $errors[] = "FALLO al gestionar el registro de datos personales " . $e->getMessage();
                return false;
            }
        }

    }

    /**
     * Preferencias de notificacion
     *
     * @return type array
     */
    public static function getPreferences($user) {
        if (!$user instanceOf User) {
            $user = self::getMini($user);
        }
        $query = self::query('SELECT
                                  updates,
                                  threads,
                                  rounds,
                                  mailing,
                                  email,
                                  tips,
                                  comlang,
                                  currency
                              FROM user_prefer
                              WHERE user = ?'
            , array($user->id));

        if (!($data = $query->fetchObject())) {
            $data = new \stdClass();
        }
        if (empty($data->comlang)) {
            $data->comlang = $user->lang;
        }

        $data->lang = $data->comlang;
        // TODO: other defaults?
        return $data;
    }

    /**
     * Actualizar las preferencias de notificación
     *
     * @return type booblean
     */
    public static function setPreferences($user, $data = array(), &$errors = array()) {
        if($user instanceOf User) $user = $user->id;

        $keys = ['updates', 'threads', 'rounds', 'mailing', 'email', 'tips', 'comlang', 'currency'];

        $values = $insert = $update = [];
        foreach ($data as $key => $value) {
            if(!in_array($key, $keys)) continue;
            $insert["`$key`"] = ":$key";
            $update[] = "`$key`=:$key";
            $values[":$key"] = $value;
        }

        if (!empty($values)) {
            $insert['`user`'] = ':user';
            $values[':user'] = $user;
            // $sql = "REPLACE INTO user_prefer SET user = :user, " . $set;
            $sql = "INSERT INTO user_prefer (" . implode(',', array_keys($insert)) .
                   ") VALUES (" . implode(',', $insert) .
                   ") ON DUPLICATE KEY UPDATE " . implode(',', $update);
            try {
                // die(\sqldbg($sql, $values));
                self::query($sql, $values);
                return true;

            } catch (\PDOException $e) {
                $errors[] = "FALLO al gestionar las preferencias de notificación " . $e->getMessage();
                return false;
            }
        }

    }

    /*
                 * Lista de proyectos cofinanciados
    */
    public static function invested($user, $publicOnly = true, $offset = 0, $limit = 12, $count = false, $unique_project= false) {
        $debug = false;
        $lang = Lang::current();
        $projects = array();
        $values = array(':user' => $user);


        list($fields, $joins) = self::getLangsSQLJoins($lang, 'project', 'id', 'Goteo\Model\Project');

        if ($publicOnly) {
            $sqlFilter = " AND project.status > 2";
        }

        if ($unique_project) {
            $groupBy = " GROUP BY project.id";
        }

        if($count) {
            $sql = "
            SELECT COUNT(project.id) FROM project
            INNER JOIN invest
                ON project.id = invest.project
                AND invest.user = :user
                AND invest.status IN ('0', '1', '3', '4')
            INNER JOIN user
                ON user.id = project.owner
            WHERE project.status < 7
            $sqlFilter
            ";
            return (int) self::query($sql, [':user' => $user])->fetchColumn();
        }

        if($limit) {
            $sql_limit = ' LIMIT ' . (int)$offset . ','. (int)$limit;
        }

        $sql = "
            SELECT
                project.id as project,
                $fields,
                project.status as status,
                project.published as published,
                project.created as created,
                project.updated as updated,
                project.success as success,
                project.closed as closed,
                project.mincost as mincost,
                project.maxcost as maxcost,
                project.amount as amount,
                project.image as image,
                project.num_investors as num_investors,
                project.num_messengers as num_messengers,
                project.project_location as project_location,
                project.social_commitment as social_commitment,
                project.num_posts as num_posts,
                project.days as days,
                project.name as name,
                user.id as user_id,
                user.name as user_name,
                project_conf.noinvest as noinvest,
                project_conf.one_round as one_round,
                project_conf.days_round1 as days_round1,
                project_conf.days_round2 as days_round2
            FROM  project
            INNER JOIN invest
                ON project.id = invest.project
                AND invest.user = :user
                AND invest.status IN ('0', '1', '3', '4')
            INNER JOIN user
                ON user.id = project.owner
            LEFT JOIN project_conf
                ON project_conf.project = project.id
            $joins
            WHERE project.status < 7
            $sqlFilter
            $groupBy
            ORDER BY  project.status ASC, project.created DESC
            $sql_limit
            ";
        // die(\sqldbg($sql, $values));

        $query = self::query($sql, $values);
        foreach ($query->fetchAll(\PDO::FETCH_CLASS, 'Goteo\Model\Project') as $proj) {
            $projects[] = Project::getWidget($proj, $lang);
        }
        return $projects;
    }

    /**
     * Metodo para cancelar la cuenta de usuario
     * Nos e borra nada, se desactiva y se oculta.
     *
     * @param string $userId
     * @return bool
     */
    public static function cancel($userId, $param = null) {
        if (self::query('UPDATE user SET active = 0, hide = 1 WHERE id = :id', array(':id' => $userId))) {
            return true;
        }
        return false;
    }

    public static function setProperty($userId, $value, $param = 'active') {
        if (in_array($param, array('active', 'hide', 'rememberme'))) {
            if (self::query("UPDATE user SET user.$param = :value WHERE id = :id", array(':id' => $userId, ':value' => $value))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Metodo para saber si el usuario ha bloqueado este envio de mailing
     *
     * @param string $userId
     * @param string $mailingCode Tipo de envio de mailing. Default: newsletter
     * @return bool
     */
    public static function mailBlock($userId, $mailingCode = 'mailing') {

        $values = array(':user' => $userId);

        $sql = "SELECT user_prefer.{$mailingCode} as blocked FROM user_prefer WHERE user_prefer.user = :user";

        $query = self::query($sql, $values);
        $block = $query->fetchColumn();
        if ($block == 1) {
            return true;
        } else {
            return false;
        }

    }

    /*
                 * Para saber si un usuario tiene traducción en cierto idioma
                 * @return: boolean
    */
    public static function isTranslated($id, $lang) {
        $sql = "SELECT id FROM user_lang WHERE id = :id AND lang = :lang";
        $values = array(
            ':id' => $id,
            ':lang' => $lang,
        );
        $query = static::query($sql, $values);
        $its = $query->fetchObject();
        if ($its->id == $id) {
            return true;
        } else {
            return false;
        }
    }

    /*
                 * Consulta simple para saber si un usuario ha cofinanciado en algun proyecto de un impulsor
                 * @return: boolean
    */
    public static function isInvestor($user, $owner, $dbg = false) {
        $sql = "SELECT COUNT(*)
        FROM project
        INNER JOIN invest
            ON invest.project = project.id
            AND invest.status IN ('0', '1', '3', '4')
            AND invest.user = :user
        WHERE project.owner = :owner
        AND project.status > 2
        ";
        $values = array(
            ':user' => $user,
            ':owner' => $owner,
        );
        if ($dbg) {
            echo str_replace(array_keys($values), array_values($values), $sql) . '<br />';
        }

        $query = static::query($sql, $values);
        $is = $query->fetchColumn();
        if ($dbg) {
            var_dump($is);
        }

        return !empty($is);
    }

    /*
                 * Consulta simple para saber si un usuario ha participado en los mensajes de algun proyecto de un impulsor
                 * @return: boolean
    */
    public static function isParticipant($user, $owner, $dbg = false) {
        $sql = "SELECT COUNT(*)
        FROM project
        INNER JOIN message
            ON message.project = project.id
            AND message.user = :user
        WHERE project.owner = :owner
        AND project.status > 2
        ";
        $values = array(
            ':user' => $user,
            ':owner' => $owner,
        );
        if ($dbg) {
            echo str_replace(array_keys($values), array_values($values), $sql) . '<br />';
        }

        $query = static::query($sql, $values);
        $is = $query->fetchColumn();
        if ($dbg) {
            var_dump($is);
        }

        return !empty($is);
    }

    /**
     * Returns an array of suggested non-existing userid based on a string
     */
    public static function suggestUserId() {
        $strings = func_get_args();
        $suggest = [];
        $originals = [];
        foreach($strings as $string) {
            $parts = preg_split("/[\s,\-\@\.]+/", $string);
            $id = '';
            foreach($parts as $part) {
                $id .= self::idealiza($part);
                if(strlen($id) < 4) continue;
                if($id) {
                    $originals[] = $id;

                    $query = self::query("SELECT id FROM user WHERE id = ?", $id);
                    if ($query->fetch()) {
                        continue;
                    }

                    $suggest[] = $id;
                    $id = '';
                }
            }
        }
        // print_r($originals);die;
        // Fill with automatic
        if($originals) {
            foreach($originals as $id) {
                do {
                    $new =  preg_replace_callback( "|(\d+)|", function ($matches) {
                            return ++$matches[1];
                        }, $id);
                    if($new === $id) {
                        $new = $id . '1';
                    }

                    $query = self::query("SELECT id FROM user WHERE id = ?", $new);
                    $id = $new;

                } while($query->fetch());
                if(!in_array($id, $suggest)) $suggest[] = $id;
            }
        }

        return $suggest;
    }

    /*
    * Return the number of active users in Goteo
    */

    static public function getTotalUsers() {
        $sql="SELECT
                    COUNT('user.id') as total
              FROM user
              WHERE user.active = 1";
        $query = static::query($sql);

        $item = $query->fetchObject(__CLASS__);

        return $item->total;

    }

}
