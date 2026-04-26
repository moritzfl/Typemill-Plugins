<?php

namespace Plugins\files;

use Typemill\Plugin;

class files extends Plugin
{
    public static function setPremiumLicense()
    {
        return false;
    }

    public static function getSubscribedEvents()
    {
        return [
            'onSystemnaviLoaded' => ['onSystemnaviLoaded', 0],
        ];
    }

    public static function addNewRoutes()
    {
        return [
            [
                'httpMethod' => 'get',
                'route'      => '/tm/files',
                'name'       => 'files.admin',
                'class'      => 'Typemill\Controllers\ControllerWebSystem:blankSystemPage',
                'resource'   => 'system',
                'privilege'  => 'view'
            ],
        ];
    }

    public function onSystemnaviLoaded($navidata)
    {
        $this->addSvgSymbol('<symbol id="icon-filemanager" viewBox="0 0 24 24"><path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-1 8h-3v3h-2v-3h-3v-2h3V9h2v3h3v2z"/></symbol>');

        $navi = $navidata->getData();

        $navi['Files'] = [
            'title'        => 'Files',
            'routename'    => 'files.admin',
            'icon'         => 'icon-filemanager',
            'aclresource'  => 'system',
            'aclprivilege' => 'view'
        ];

        if (trim($this->route, '/') == 'tm/files') {
            $navi['Files']['active'] = true;
            $settings = $this->getSettings();
            $config = [
                'maxFileUploads'      => $settings['maxfileuploads'] ?? null,
                'uploadMaxFilesize'   => ini_get('upload_max_filesize') ?: null,
                'postMaxSize'         => ini_get('post_max_size') ?: null,
                'maxFileUploadsCount' => ini_get('max_file_uploads') ?: null,
            ];
            $configJs = 'const filesConfig = ' . json_encode($config) . ';';
            $template = file_get_contents(__DIR__ . '/js/systemfiles.html');
            $js       = file_get_contents(__DIR__ . '/js/systemfiles.js');
            $this->addInlineJS($configJs . ' const filesTemplate = ' . json_encode($template) . '; ' . $js);
        }

        $navidata->setData($navi);
    }
}
