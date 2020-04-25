<?php
define('DEBUG', false);
define('API_USERNAME',"harun@hrn2k1.onmicrosoft.com");
define('API_PASSWORD', "Passsss");
define('SP_TENANT', "https://hrn2k1.sharepoint.com");
define('SP_SITE', SP_TENANT . "/sites/dev");
define('LOGIN_URL', "https://login.microsoftonline.com/extSTS.srf");
define('SIGNIN_URL', SP_TENANT . "/_forms/default.aspx?wa=wsignin1.0");
define('CONTEXT_URL', SP_SITE . "/_api/contextinfo");
define('SP_PARENT_FOLDER_URL', '/sites/dev/Shared Documents');
define('GET_LIST_ITEMS_API', SP_SITE . "/_api/web/lists/getbytitle('Test')/items");
define('UPLOAD_FILE_API', SP_SITE . "/_api/web/GetFolderByServerRelativeUrl({FOLDER_NAME})/Files/add(url={FILE_NAME},overwrite=true)");
define('CREATE_FOLDER_API', SP_SITE . '/_api/web/folders');

function get_cookie_digest(){
    $token_request = file_get_contents("TokenEnvelope.xml");
    $token_request = str_replace('{USERNAME}', API_USERNAME, $token_request);
    $token_request = str_replace('{PASSWORD}', API_PASSWORD, $token_request);
    $token_request = str_replace('{ENDPOINTREFERENCE}', SP_SITE, $token_request);
    $client = curl_init();
    curl_setopt($client, CURLOPT_URL , LOGIN_URL);
    curl_setopt($client, CURLOPT_POST, 1);
    curl_setopt($client, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/xml",
        "Content-Length: " . strlen($token_request)
    ));
    curl_setopt($client, CURLOPT_POSTFIELDS, $token_request);
    curl_setopt($client, CURLOPT_RETURNTRANSFER, 1);
    
    $result = curl_exec($client);

    $resXml =  new SimpleXMLElement($result);
    $sTokens = $resXml->xpath('//S:Envelope//S:Body//wst:RequestSecurityTokenResponse//wst:RequestedSecurityToken//wsse:BinarySecurityToken');
    $sTokenValue = '';
    foreach ($sTokens as $sToken) {
        $sTokenValue = (string)$sToken;
        break;
    }
    
    curl_setopt($client, CURLOPT_URL , SIGNIN_URL);
    //curl_setopt($client, CURLOPT_POST, 1);
    curl_setopt($client, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($client, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($client, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/x-www-form-urlencoded",
        "Content-Length: " . strlen($sTokenValue)
    ));
    curl_setopt($client, CURLOPT_POSTFIELDS, $sTokenValue);
    curl_setopt($client, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($client, CURLOPT_HEADER, 1);

    $result = curl_exec($client);

    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $matches);
    $cookies = array();
    foreach($matches[0] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }
    $cookie_rtFa = $cookies['set-cookie:_rtFa'];
    $cookie_FedAuth = $cookies['set-cookie:_FedAuth'];
    $cookie_RpsContext = $cookies['set-cookie:_RpsContextCookie'];

    $cookie_header = "FedAuth=" . str_replace(' ', '+', $cookie_FedAuth) ."; rtFa=" . str_replace(' ', '+', $cookie_rtFa) . "; RpsContextCookie=" . str_replace(' ', '+', $cookie_RpsContext ); //. ";SPWorkLoadAttribution=Url=" . $tenant . "/";
    //echo "<br/>Cookie<br/>".$cookie_header;
    curl_setopt($client, CURLOPT_URL , CONTEXT_URL);
    curl_setopt($client, CURLOPT_POST, 1);
    curl_setopt($client, CURLOPT_VERBOSE, true);
    curl_setopt($client, CURLOPT_HTTPHEADER, array(
        "Cookie: " . $cookie_header
    ));
    curl_setopt($client, CURLOPT_POSTFIELDS, null);
    curl_setopt($client, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($client, CURLOPT_HEADER, 0);
    
    $result = curl_exec($client);
    $resXml =  new SimpleXMLElement($result);
    $dvs = $resXml->xpath('//d:GetContextWebInformation//d:FormDigestValue');
    $digestValue = '';
    foreach ($dvs as $dv) {
        $digestValue = (string)$dv;
        break;
    }
    curl_close($client);
    return array(
        "Cookie" => $cookie_header,
        "X-RequestDigest" => $digestValue
    );
}
function create_folder($cookie_digest = null, $folder_name){
    
    if($cookie_digest == null){
        $cookie_digest = get_cookie_digest();
    }
    $request_headers = array(
        "Accept: application/json;odata=verbose",
        "Content-Type: application/json;odata=verbose",
        "Cookie: " . $cookie_digest['Cookie'],
        "X-RequestDigest: " . $cookie_digest['X-RequestDigest']
        );
    $payload = '{"__metadata":{"type":"SP.Folder"},"ServerRelativeUrl":"'. rawurlencode(SP_PARENT_FOLDER_URL . "/" . $folder_name) .'"}';
    $http_client = curl_init();
    $request_options = array(
        CURLOPT_URL => CREATE_FOLDER_API,
        CURLOPT_HEADER => true,
        CURLOPT_POST => 1,
        CURLOPT_HTTPHEADER => $request_headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true
    );
    curl_setopt_array($http_client, $request_options);
    curl_exec($http_client);
    if(!curl_errno($http_client))
    {
        $info = curl_getinfo($http_client);
        if(DEBUG) print_r($info);
        if ($info['http_code'] == 201)
            return array('status' => 'SUCCESS', 'code' => 201, 'message' => 'Successfully created', 'info' => $info);
        else
            return array('status' => 'FAILED', 'code' => $info['http_code'], 'info' => $info);
    }
    else
    {
        return array('status' => 'FAILED', 'code' => 500, 'error' => curl_error($http_client));
    }
    curl_close($http_client);
}
function upload_file($cookie_digest = null, $folder_name, $file){
    $filedata = $file['tmp_name'];
    if($filedata == null || $filedata == '') return;
    if($cookie_digest == null){
        $cookie_digest = get_cookie_digest();
    }

    $filename = $file['name'];
    $filesize = $file['size'];
    $type = $file['type'];
    $folder_url = SP_PARENT_FOLDER_URL . "/" . $folder_name;
    $url = str_replace('{FOLDER_NAME}', rawurlencode( "'".$folder_url."'"), UPLOAD_FILE_API);
    $url = str_replace('{FILE_NAME}', rawurlencode("'".$filename."'"), $url);
    $boundary = 'WebKitFormBoundary' .  uniqid();
    $payload = '';
    $payload .= '----------------------------' . $boundary . "\r\n";
    $payload .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
    $payload .= 'Content-Type: ' . $type . "\r\n\r\n";
    $payload .=  file_get_contents($filedata);
    $payload .= "\r\n";
    $payload .= '----------------------------' . $boundary . '--';
    $headers = array(
        "Accept: application/json",
        "Content-Type: multipart/form-data; boundary=--------------------------". $boundary,
        "Cookie: " . $cookie_digest['Cookie'],
        "X-RequestDigest: " . $cookie_digest['X-RequestDigest']
    );
    
    $http_client = curl_init();
    $options = array(
        CURLOPT_URL => $url,
        CURLOPT_HEADER => true,
        CURLOPT_POST => 1,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_INFILESIZE => $filesize,
        CURLOPT_RETURNTRANSFER => true
    );
    curl_setopt_array($http_client, $options);
    curl_exec($http_client);
    if(!curl_errno($http_client))
    {
        $info = curl_getinfo($http_client);
        if ($info['http_code'] == 200)
            return array('status' => 'SUCCESS', 'code' => 200, 'message' => 'Successfully uploaded', 'info' => $info);
        else
            return array('status' => 'FAILED', 'code' => $info['http_code'], 'info' => $info);
    }
    else
    {
        return array('status' => 'FAILED', 'code' => 500, 'error' => curl_error($http_client));
    }
    curl_close($chttp_clienth);   
}
function get_list_items($cookie_digest = null){
    if($cookie_digest == null){
        $cookie_digest = get_cookie_digest();
    }
    $client = curl_init();
    curl_setopt($client, CURLOPT_URL , GET_LIST_ITEMS_API);
    //curl_setopt($client, CURLOPT_POST, 0);
    curl_setopt($client, CURLOPT_HTTPHEADER, array(
        "Accept: application/json;odata=verbose",
        "Cookie: " . $cookie_digest['Cookie'],
        "X-RequestDigest: " . $cookie_digest['X-RequestDigest']
    ));
    curl_setopt($client, CURLOPT_RETURNTRANSFER, 1);
    $result = curl_exec($client);
    echo "<br/>List Items<br/>";
    print_r($result);
    curl_close($client);
}
?>
<?php

if (isset($_POST['btnUpload']))
{
    
    $total_files = 0;
    $file_count = count($_FILES['files']['name']);
    $files = array();
    for($i=0; $i < $file_count; $i++){
        $file_path = $_FILES['files']['tmp_name'][$i];
        $file_size = $_FILES['files']['size'][$i];
        if ($file_path == '' || $file_size <=0)
            continue;
        $file = array(
            'name' => $_FILES['files']['name'][$i],
            'tmp_name' => $file_path,
            'type' => $_FILES['files']['type'][$i],
            'size' => $file_size
        );
        if(DEBUG) {
            print_r($file);
            echo "<br/>";
        }
        array_push($files, $file);
        $total_files++;
      }
    $message = '';
    if ($total_files > 0) {
        $cookie_digest = get_cookie_digest();
        $folder_name = date("Ymd-Hi");
        $result = create_folder($cookie_digest,  $folder_name);
        if($result['status'] != 'SUCCESS') {
            $message .= '<br/>Directory "'.$folder_name.'" creation failed. Error: '.$result['error'];
            print_r($result['info']);
        }
        else {
            $message .= '<br/><ul>';
            for($i=0; $i < $total_files; $i++){
                $upload_result = upload_file($cookie_digest, $folder_name, $files[$i] );
                if($upload_result['status'] != 'SUCCESS'){
                    $message .= '<li>File "'.$files[$i]['name'].'" upload failed. Error: '. $result['error'] . '</li>';
                }
                else {
                    $message .= '<li>File "'.$files[$i]['name'].'" uploaded successfully.</li>';
                }
            }
            $message .= '</ul><br/>';
            $folder_url = SP_TENANT . SP_PARENT_FOLDER_URL . "/" . $folder_name;
            $message .= '<br/>The files are availabe at <br/><a target="_blank" href="'.$folder_url.'">'.$folder_url.'</a>';
        }
    }
    else{
        $message .= 'Please select files to upload.';
    }
    
}
?>
<html class="js">
    <head>
    <style type="text/css">
        .container {
            width: 100%;
            max-width: 680px;
            text-align: center;
            margin: 0 auto;
        }
        .box
        {
            font-size: 1.25rem; /* 20 */
            background-color: #c8dadf;
            position: relative;
            padding: 100px 20px;
        }
        .box.has-advanced-upload
        {
            outline: 2px dashed #92b0b3;
            outline-offset: -10px;

            -webkit-transition: outline-offset .15s ease-in-out, background-color .15s linear;
            transition: outline-offset .15s ease-in-out, background-color .15s linear;
        }
        .box.is-dragover
        {
            outline-offset: -20px;
            outline-color: #c8dadf;
            background-color: #fff;
        }
        .box__dragndrop,
        {
            display: none;
        }
        .box.has-advanced-upload .box__dragndrop
        {
            display: inline;
        }
        .box.has-advanced-upload .box__icon
        {
            width: 100%;
            height: 80px;
            fill: #92b0b3;
            display: block;
            margin-bottom: 40px;
            cursor: pointer;
        }

        @-webkit-keyframes appear-from-inside
        {
            from	{ -webkit-transform: translateY( -50% ) scale( 0 ); }
            75%		{ -webkit-transform: translateY( -50% ) scale( 1.1 ); }
            to		{ -webkit-transform: translateY( -50% ) scale( 1 ); }
        }
        @keyframes appear-from-inside
        {
            from	{ transform: translateY( -50% ) scale( 0 ); }
            75%		{ transform: translateY( -50% ) scale( 1.1 ); }
            to		{ transform: translateY( -50% ) scale( 1 ); }
        }

    .box__restart
    {
        font-weight: 700;
    }
    .box__restart:focus,
    .box__restart:hover
    {
        color: #39bfd3;
    }

    .js .box__file
    {
        width: 0.1px;
        height: 0.1px;
        opacity: 0;
        overflow: hidden;
        position: absolute;
        z-index: -1;
    }
    .js .box__file + label
    {
        max-width: 80%;
        text-overflow: ellipsis;
        white-space: nowrap;
        cursor: pointer;
        display: inline-block;
        overflow: hidden;
    }
    .js .box__file + label:hover strong,
    .box__file:focus + label strong,
    .box__file.has-focus + label strong
    {
        color: #39bfd3;
    }
    .js .box__file:focus + label,
    .js .box__file.has-focus + label
    {
        outline: 1px dotted #000;
        outline: -webkit-focus-ring-color auto 5px;
    }
        .js .box__file + label *
        {
            /* pointer-events: none; */ /* in case of FastClick lib use */
        }

    .no-js .box__file + label
    {
        display: none;
    }

    .no-js .box__button
    {
        display: block;
    }
    .box__button
    {
        font-weight: 700;
        color: #e5edf1;
        background-color: #39bfd3;
        /* display: none; */
        padding: 8px 16px;
        margin: 1px auto 0;
        width: 100%;
    }
        .box__button:hover,
        .box__button:focus
        {
            background-color: #0f3c4b;
        }
    #selected_files {
        text-align: left;
    }
    #selected_files ul {
        list-style: decimal;
    }
</style>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
        <script type="text/javascript">
            function files_selection_changed(e){
                debugger;
                var files = document.getElementById('selectfile').files;
                    var file_html = "<ul>";
                    for(var i = 0; i < files.length; i++){
                        file_html += "<li>" + files[i].name + "</li>";
                    }
                    file_html += "</ul>";
                    $('#selected_files').html(file_html);
            }
            var fileobj;
            function upload_files(e) {
                e.preventDefault();
                debugger;
                var files = e.dataTransfer.files;
                document.getElementById('selectfile').files = files;
                files_selection_changed(e);
            }
            
            function file_explorer() {
                document.getElementById('selectfile').click();
            }

        </script>
    </head>
    <body>
    <div class="container">
        <form method="post" name="frmUpload" enctype='multipart/form-data' >
        <div  id="drop_file_zone" ondrop="upload_files(event)" ondragover="return false" class="box has-advanced-upload">
            <div class="box__input" id="drag_upload_file">
            <svg onclick="file_explorer();" class="box__icon" xmlns="http://www.w3.org/2000/svg" width="50" height="43" viewBox="0 0 50 43"><path d="M48.4 26.5c-.9 0-1.7.7-1.7 1.7v11.6h-43.3v-11.6c0-.9-.7-1.7-1.7-1.7s-1.7.7-1.7 1.7v13.2c0 .9.7 1.7 1.7 1.7h46.7c.9 0 1.7-.7 1.7-1.7v-13.2c0-1-.7-1.7-1.7-1.7zm-24.5 6.1c.3.3.8.5 1.2.5.4 0 .9-.2 1.2-.5l10-11.6c.7-.7.7-1.7 0-2.4s-1.7-.7-2.4 0l-7.1 8.3v-25.3c0-.9-.7-1.7-1.7-1.7s-1.7.7-1.7 1.7v25.3l-7.1-8.3c-.7-.7-1.7-.7-2.4 0s-.7 1.7 0 2.4l10 11.6z"></path></svg>
            <input type="file" id="selectfile" name="files[]" multiple onchange="files_selection_changed(event);" class="box__file"/>
            <label for="selectfile"><strong>Choose files</strong><span class="box__dragndrop"> or drag files here</span>.</label>
            </div>
            <div id="selected_files">
              <?php if (isset($message)) { echo "<pre>" . $message . "</pre>"; } ?>
            </div>
        </div>
        <input name="btnUpload" type="submit" value="Upload the files" class="box__button" />
        </form>
        </div>
    </body>
</html>