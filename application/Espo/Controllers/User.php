<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2020 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Controllers;

use \Espo\Core\Exceptions\Error;
use \Espo\Core\Exceptions\NotFound;
use \Espo\Core\Exceptions\Forbidden;
use \Espo\Core\Exceptions\BadRequest;
use PDOException;
use stdClass;

class User extends \Espo\Core\Controllers\Record
{
    public function actionAcl($params, $data, $request)
    {
        $userId = $request->get('id');
        if (empty($userId)) {
            throw new Error();
        }

        if (!$this->getUser()->isAdmin() && $this->getUser()->id != $userId) {
            throw new Forbidden();
        }

        $user = $this->getEntityManager()->getEntity('User', $userId);
        if (empty($user)) {
            throw new NotFound();
        }

        return $this->getAclManager()->getMap($user);
    }

    public function postActionChangeOwnPassword($params, $data, $request)
    {
        if (!property_exists($data, 'password') || !property_exists($data, 'currentPassword')) {
            throw new BadRequest();
        }
        return $this->getService('User')->changePassword($this->getUser()->id, $data->password, true, $data->currentPassword);
    }

    public function postActionChangePasswordByRequest($params, $data, $request)
    {
        if (empty($data->requestId) || empty($data->password)) {
            throw new BadRequest();
        }

        return $this->getService('User')->changePasswordByRequest($data->requestId, $data->password);
    }

    public function postActionPasswordChangeRequest($params, $data, $request)
    {
        if (empty($data->userName) || empty($data->emailAddress)) {
            throw new BadRequest();
        }

        $userName = $data->userName;
        $emailAddress = $data->emailAddress;
        $url = null;
        if (!empty($data->url)) {
            $url = $data->url;
        }

        return $this->getService('User')->passwordChangeRequest($userName, $emailAddress, $url);
    }

    public function postActionGenerateNewApiKey($params, $data, $request)
    {
        if (empty($data->id)) throw new BadRequest();
        if (!$this->getUser()->isAdmin()) throw new Forbidden();
        return $this->getRecordService()->generateNewApiKeyForEntity($data->id)->getValueMap();
    }

    public function postActionGenerateNewPassword($params, $data, $request)
    {
        if (empty($data->id)) throw new BadRequest();
        if (!$this->getUser()->isAdmin()) throw new Forbidden();
        $this->getRecordService()->generateNewPasswordForUser($data->id);
        return true;
    }

    public function beforeCreateLink()
    {
        if (!$this->getUser()->isAdmin()) throw new Forbidden();
    }

    public function beforeRemoveLink($params, $data, $request)
    {
        if (!$this->getUser()->isAdmin()) throw new Forbidden();
    }


    public function getActionGenerateKeyUser($params, $data, $request) {

        $dataRequest = $request->get('request');

        $public_key = $this->publicKey();

        $private_key = $this->privateKey();

        $encrypt_method = "AES-256-CBC";
        $secret_key = 'SecretKey@2020';
        $secret_iv = 'SecretIV@2020!';

        $public_key_local = hash('sha256', $secret_key);
        $private_key_local = substr(hash('sha256', $secret_iv), 0, 16);

        // encrypted
        $output = openssl_encrypt($dataRequest, $encrypt_method, $public_key_local, 0, $private_key_local);
        $baseEncrypt = base64_encode($output);
        openssl_public_encrypt($dataRequest, $baseEncrypt, $public_key);
        return $baseEncrypt;
    }
    

    public function postActionLogin($params, $data, $request) {

        $public_key = $this->publicKey();
        $private_key = $this->privateKey();


        $encrypt_method = "AES-256-CBC";
        $secret_key = 'SecretKey@2020';
        $secret_iv = 'SecretIV@2020!';

        $public_key_local = hash('sha256', $secret_key);
        $private_key_local = substr(hash('sha256', $secret_iv), 0, 16);

        $baseDecrypt = base64_decode($data);
        $decrypted = openssl_decrypt($baseDecrypt, $encrypt_method, $public_key_local, 0, $private_key_local);

        openssl_private_decrypt($baseDecrypt, $decrypted, $private_key);

        if($decrypted == false)
            return json_encode(array("result" => false));
       
        $user = json_decode($decrypted);

        $getEntityManager = $this->getEntityManager();

        $findUser = $getEntityManager->getRepository('User')->where(['userName' => $user->userName])->findOne();

        if (!$findUser) 
        {
            $account = $getEntityManager->getEntity('User');
            $passwordHash = new \Espo\Core\Utils\PasswordHash($this->getConfig());


            // set data save database
            $dataSet = new stdClass();
            $dataSet->userName = $user->userName;
            $dataSet->password = $passwordHash->hash($user->userName);
            


            $findParent = $getEntityManager->getRepository('User')->where(['id' => $user->parentId])->findOne();

            if($findParent) 
            {
                $dataSet->createdById = $findParent->id;
                $dataSet->type = 'regular';
            }
            else
                $dataSet->type = 'admin';

            $account->set($dataSet);

            $this->getEntityManager()->saveEntity($account);


             
             if($findParent)
             {

                // save role user
                  $role = $this->getRoleUser();
                  $idRole = $role[0]->id;
                
                  $this->insertRoleUser($idRole, $account->id);
                  

                // update create by id parent
                $account->set('createdById', $findParent->id);
                $getEntityManager->saveEntity($account);
             }




            // settings disabled color
            $preferences = $this->getEntityManager()->getEntity('Preferences', $account->id);
            
            $preferences->set([
                'id' =>  $account->id,
                'scopeColorsDisabled' =>  true
            ]);
           
            $this->getEntityManager()->saveEntity($preferences);

            return json_encode(array("result" => $user->userName));
           
        }
        else
            return json_encode(array("result" => $findUser->get('userName')));
    }


    public function postActionDecode($params, $data, $request) {

        $private_key = $this->privateKey();


        $encrypt_method = "AES-256-CBC";
        $secret_key = 'SecretKey@2020';
        $secret_iv = 'SecretIV@2020!';

        $public_key_local = hash('sha256', $secret_key);
        $private_key_local = substr(hash('sha256', $secret_iv), 0, 16);


        $baseDecrypt = base64_decode($data);
        $decrypted = openssl_decrypt($baseDecrypt, $encrypt_method, $public_key_local, 0, $private_key_local);

        openssl_private_decrypt($baseDecrypt, $decrypted, $private_key);

        if($decrypted == false)
            return json_encode(array("result" => false));
       
        $user = json_decode($decrypted);

        return json_encode(['result' => $user->userName]);
    }



    public function getRoleUser() {
        $selectParams = [
            'select' => [
                'id', 'name'
            ],
            'whereClause' => [
              'name' => 'user'
            ],
          ];
          
          $pdo = $this->getEntityManager()->getPDO();
          $sql = $this->getEntityManager()->getQuery()->createSelectQuery('Role', $selectParams);
          $sth = $pdo->prepare($sql);
          $sth->execute();
          
          // results
          return $sth->fetchAll(\PDO::FETCH_OBJ); 
    }

    public function privateKey() {
        return '-----BEGIN RSA PRIVATE KEY-----
        MIIEpAIBAAKCAQEAsryD4bTScVLzo9XOrYLCK8ZzjR/D01zd6h7OOhSeFx5vokHK
        0pHU5zA7jZmrqi2djj/9rW3cjjdKsKudNrg9qdMUlU9nKcaC3d3odRqMhIJK/keL
        WG18GhOfXlWdccWtrxdmj8yILpoKsS0FICcwQIr6fgKb+Sfg6aO1HSsRYUiwX0Qp
        INXceo+Tzt/7ojuqCJgfAKGV3TbyM9rcEFd9EEfzP1T3fKUQNPA2oE8hLlbzoDvr
        KO9q4KkPAHjRKJtASnFG27dORTUqml6QOWmQosBLoiRg3DRGKDSlMSfzCL8KJI0g
        CrhzGPphSz5NcJ/JE59Y2r+i8Gqb3ZsRAqnMhQIDAQABAoIBAA5S1S7NUuAKCcRj
        agC1reHcMX5pwUO6+X29okE9Tb4EjmWuaBaf4TP1xY//dKZaqXmw3x964mXzQGUQ
        G7U65pYpeEjO6DGM2wf0tKBN1Fz6JBBko2IexAdC806YBdoMQXL6qRl1BqTVa23v
        ca219kP130Uh6GX7MCcJG4aO4QGusyFuGxf6PmJ6nsJAi1GfnjJDvxNUmGVNc/uj
        COR/E0ZSJhCiYl9551Uyb+2z6xw13mgDaDnkCN5KIFuolND0kSPlt45Bf+X4ZIof
        m6rg1X4zibMXD7MZm0B0XybBNsV+tZ/L/qg9fsCqRSPtVIbjtB4CK1KhTwp8MHIN
        SwDUowECgYEA67RqUf0qCyPmsU9a/1QnaAuTf2/j71Gn7d0aILdQgWxQ2aQGqCLQ
        GH6eToZCraRquQci2zPJUAXTnYwDRu3zDtqwrNSEf3X1gaL3i8Owmn3Z4CWOu+/Z
        GZf1FHIK8u4psCKvfuWis3Fc+U28yz4VNP18FF+EcklF68kcoth63FUCgYEAwiBc
        Dprt/leV6XmCX9Spnbqh5saRy39hVJ1hjxyWTK5spm/OEN5mec2hZqHz2FVtXmGj
        F3+RNKxIvQR/p8pI8ahZrrJVaHlyOxLi7uQ/eZprM5ifpZkJ81X4OXDpQhFY8+oA
        BSecuCP1QE226L4hiUeMruXZ0SBCYDoJ+tT/X3ECgYEAxHS7qVq5kE98GI1r6yTZ
        M39ykOVRtkb5EOkYvewMzdLaEI0Gf12E1OW0TP7a1KAV0+J+yvV+9ZPlrYrlsFjk
        HZ1pZKQ9S3+mAUczzOpuaFY9IvYq4bon2Y0uWLw1JAYd+RDv9XSxB5+VPrXshfpY
        8rHUmaaDXj50aPJXS+Pjg4ECgYBd1gSJrsxB+JZncCvcJNEEhFWEVxHceytSGm0x
        H1FrqZitbzf9oXOJhyNwl/Jl7Q1b5PhQAILxIiJa89S9B6Pw5D7Nmjh8ss/LzFpT
        vZMNNd6B51GsW2ia7kB5i6HFJNuJs9/knfw8am0/cxFqOZW+ZQ6U8Fg0pVJQb12p
        mNIEkQKBgQCH1uHutWP3Gb+OrF+Mt9HxthphDR8ybpwH2gs3tHLnSc+emPOoAt6X
        LqzXCcx81OX9v3DS2j0k8l2gbalMzulrW3MI/JBoqrk7C+ukCzfE/xW3sQ01fTNQ
        13RJ1OYrA7Ot7fuJUXCb80xWRsIcc0Nct7mA9PGu3JtqZosI9BmWkg==
        -----END RSA PRIVATE KEY-----';
    }

    public function publicKey() {
        return "-----BEGIN PUBLIC KEY-----
        MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsryD4bTScVLzo9XOrYLC
        K8ZzjR/D01zd6h7OOhSeFx5vokHK0pHU5zA7jZmrqi2djj/9rW3cjjdKsKudNrg9
        qdMUlU9nKcaC3d3odRqMhIJK/keLWG18GhOfXlWdccWtrxdmj8yILpoKsS0FICcw
        QIr6fgKb+Sfg6aO1HSsRYUiwX0QpINXceo+Tzt/7ojuqCJgfAKGV3TbyM9rcEFd9
        EEfzP1T3fKUQNPA2oE8hLlbzoDvrKO9q4KkPAHjRKJtASnFG27dORTUqml6QOWmQ
        osBLoiRg3DRGKDSlMSfzCL8KJI0gCrhzGPphSz5NcJ/JE59Y2r+i8Gqb3ZsRAqnM
        hQIDAQAB
        -----END PUBLIC KEY-----";
    }


    public function insertRoleUser($idRole, $idUser) {
        try {
            $pdo = $this->getEntityManager()->getPDO();
    
            $sql = "INSERT INTO role_user (role_id, user_id, deleted) VALUES (?, ?, ?)";
            $pdo->prepare($sql)->execute([$idRole, $idUser, 0]);
        } catch (PDOException $e) {
           throw new Error($e);
        }
        
    }
}
