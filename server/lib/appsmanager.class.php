<?php

class AppsManager
{

    public function getList($without_fetch = false){

        $db_apps = Mysql::getInstance()->from('apps')->orderby('id')->get()->all();

        $apps = array_map(function($app) use ($without_fetch){

            if (!$without_fetch) {

                try{
                    $repo = new GitHub($app['url']);
                    $info = $repo->getFileContent('package.json');
                }catch (GitHubException $e){

                }
                $app['name'] = isset($info['name']) ? $info['name'] : $app['name'];
                $app['alias'] = empty($app['alias']) && isset($info['name']) ? AppsManager::safeFilename($info['name']) : $app['alias'];
                $app['available_version'] = isset($info['version']) ? $info['version'] : '';
                $app['description'] = isset($info['description']) ? $info['description'] : $app['description'];

            }

            if ($app['current_version']){
                $app['installed'] = !empty($app['alias']) && is_dir(realpath(PROJECT_PATH.'/../../'
                    .Config::getSafe('apps_path', 'stalker_apps/')
                    .$app['alias']
                    .'/'.$app['current_version']));
            }else{
                $app['installed'] = false;
            }


            $app['app_url'] = 'http'.(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? 's' : '')
                .'://'.$_SERVER['HTTP_HOST']
                .'/'.Config::getSafe('apps_path', 'stalker_apps/')
                .$app['alias']
                .'/'.$app['current_version'];

            return $app;
        }, $db_apps);

        return $apps;
    }

    public function getAppInfo($app_id){

        $app = Mysql::getInstance()->from('apps')->where(array('id' => $app_id))->get()->first();

        if (empty($app)){
            return false;
        }

        $repo = new GitHub($app['url']);

        $info = $repo->getFileContent('package.json');

        $app['name']              = isset($info['name']) ? $info['name'] : '';
        $app['alias']             = empty($info['alias']) ? AppsManager::safeFilename($info['name']) : $info['alias'] ;
        $app['available_version'] = isset($info['version']) ? $info['version'] : '';
        $app['description']       = isset($info['description']) ? $info['description'] : '';

        $option_values = json_decode($app['options'], true);

        if (empty($option_values)){
            $option_values = array();
        }

        unset($app['options']);

        if ($app['current_version']){
            $app['installed'] = is_dir(realpath(PROJECT_PATH.'/../../'
                .Config::getSafe('apps_path', 'stalker_apps/')
                .$app['alias']
                .'/'.$app['current_version']));
        }else{
            $app['installed'] = false;
        }

        $app['app_url'] = 'http'.(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? 's' : '')
            .'://'.$_SERVER['HTTP_HOST']
            .'/'.Config::getSafe('apps_path', 'stalker_apps/')
            .$app['alias']
            .'/'.$app['current_version'];

        $releases = $repo->getReleases(50);

        if (is_array($releases)){
            $releases = array_map(function($release) use ($app, $option_values){

                $repo = new GitHub($app['url']);
                $repo->setRelease($release['tag_name']);
                $info = $repo->getFileContent('package.json');

                $option_list = isset($info['options']) ? $info['options'] : array();

                $option_list = array_map(function($option) use ($option_values){

                    if (isset($option_values[$option['name']])){
                        $option['value'] = $option_values[$option['name']];
                    }elseif (!isset($option['value'])){
                        $option['value'] = null;
                    }

                    if (isset($option['info'])){
                        $option['desc'] = $option['info'];
                    }

                    return $option;
                }, $option_list);

                return array(
                    'version'     => $release['tag_name'],
                    'name'        => $release['name'],
                    'published'   => $release['published_at'],
                    'description' => $release['body'],
                    'installed'   => is_dir(realpath(PROJECT_PATH.'/../../'
                        .Config::getSafe('apps_path', 'stalker_apps/')
                        .$app['alias']
                        .'/'.$release['tag_name'])),
                    'current'     => $release['tag_name'] == $app['current_version'],
                    'options'     => $option_list
                );
            }, $releases);
        }

        $app['versions'] = $releases;

        return $app;
    }

    public function installApp($app_id){

        $app = Mysql::getInstance()->from('apps')->where(array('id' => $app_id))->get()->first();

        if (empty($app)){
            return false;
        }

        $repo = new GitHub($app['url']);
        $versions = $repo->getReleases(1);
        $info = $repo->getFileContent('package.json');

        if (count($versions) == 0){
            return false;
        }

        $latest_release = $versions[0];

        if ($latest_release['tag_name'] == $app['current_version']){
            return false;
        }

        $tmp_file = '/tmp/'.uniqid('app_').'.zip';

        $zip_url = 'https://github.com/'.$repo->getOwner().'/'.$repo->getRepository().'/archive/'.$latest_release['tag_name'].'.zip';

        file_put_contents($tmp_file, fopen($zip_url, 'r', false, stream_context_create(array('http'=>array('header' => "User-Agent: stalker_portal\r\n")))));

        $path = PROJECT_PATH.'/../../'.Config::getSafe('apps_path', 'stalker_apps/').self::safeFilename($info['name']);

        umask(0);
        if (!is_dir($path)){
            mkdir($path, 0755, true);
        }

        $archive = new ZipArchive();

        if ($archive->open($tmp_file) === true) {
            $entry = $archive->getNameIndex(0);
            $dir = substr($entry, 0, strpos($entry, '/'));
            $result = $archive->extractTo($path);
            $archive->close();
            rename($path.'/'.$dir, $path.'/'.$latest_release['tag_name']);
        }else{
            return false;
        }

        unlink($tmp_file);

        if (!empty($result)){

            $update_data = array('current_version' => $latest_release['name']);
            if (empty($app['alias'])){

                $update_data['alias'] = self::safeFilename($info['name']);

                if (empty($app['name'])){
                    $update_data['name'] = $info['name'];
                }
            }

            if (!empty($info['description'])){
                $update_data['description'] = $info['description'];
            }

            if (!empty($info['config']['icons'])){
                $update_data['icons'] = $info['config']['icons'];
            }else{
                $update_data['icons'] = 'icons';
            }

            if (!empty($info['config']['backgroundColor'])){
                $update_data['icon_color'] = $info['config']['backgroundColor'];
            }

            Mysql::getInstance()->update('apps',
                $update_data,
                array('id' => $app_id)
            );
        }

        return $result;
    }

    public function updateApp($app_id, $version = null){

        $app = Mysql::getInstance()->from('apps')->where(array('id' => $app_id))->get()->first();

        if (empty($app)){
            return false;
        }

        if ($version === null){
            return $this->installApp($app_id);
        }

        $tmp_file = '/tmp/'.uniqid('app_').'.zip';

        $repo = new GitHub($app['url']);

        $zip_url = 'https://github.com/'.$repo->getOwner().'/'.$repo->getRepository().'/archive/'.$version.'.zip';

        file_put_contents($tmp_file, fopen($zip_url, 'r', false, stream_context_create(array('http'=>array('header' => "User-Agent: stalker_portal\r\n")))));

        if (empty($app['alias'])){
            $app['alias'] = self::safeFilename($app['name']);
        }

        $path = PROJECT_PATH.'/../../'.Config::getSafe('apps_path', 'stalker_apps/').$app['alias'];

        umask(0);
        if (!is_dir($path)){
            mkdir($path, 0755, true);
        }

        $archive = new ZipArchive();

        if ($archive->open($tmp_file) === true) {
            $entry = $archive->getNameIndex(0);
            $dir = substr($entry, 0, strpos($entry, '/'));
            $result = $archive->extractTo($path);
            $archive->close();
            rename($path.'/'.$dir, $path.'/'.$version);
        }else{
            return false;
        }

        unlink($tmp_file);

        if ($result){
            $update_data = array('current_version' => $version);

            $update_data['alias'] = $app['alias'];

            if (!isset($repo)){
                $repo = new GitHub($app['url']);
            }
            $info = $repo->getFileContent('package.json');

            if (!empty($info['description'])){
                $update_data['description'] = $info['description'];
            }

            if (!empty($info['config']['icons'])){
                $update_data['icons'] = $info['config']['icons'];
            }else{
                $update_data['icons'] = 'icons';
            }

            if (!empty($info['config']['backgroundColor'])){
                $update_data['icon_color'] = $info['config']['backgroundColor'];
            }

            Mysql::getInstance()->update('apps', $update_data, array('id' => $app_id));
        }

        return $result;
    }

    public function deleteApp($app_id, $version = null){

        $app = Mysql::getInstance()->from('apps')->where(array('id' => $app_id))->get()->first();

        if ($version === null){
            $version = $app['current_version'];
        }

        $path = realpath(PROJECT_PATH.'/../../'.Config::getSafe('apps_path', 'stalker_apps/').$app['alias'].'/'.$version);

        if (is_dir($path)){
            self::delTree($path);
        }

        return false;
    }

    public function startAutoUpdate(){

        $need_to_update = Mysql::getInstance()->from('apps')->where(array('status' => 1, 'autoupdate' => 1))->get()->all();

        foreach ($need_to_update as $app){
            $this->updateApp($app['id']);
        }

    }

    public static function safeFilename($filename){
        $except = array('\\', '/', ':', '*', '?', '"', '<', '>', '|');
        return strtolower(str_replace($except, '', $filename));
    }

    private static function delTree($dir) {
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }
}
