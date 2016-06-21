<?php
	
/**

https://redcap.stanford.edu/plugins/summarize/?token_user=pzornio&if=study_coordinator_intake&df=study_coordinator_intake


	This is a summarization plugin designed to take the values specified in the input and make a nice html summary to write to the output field in the project.

	input parameters:
	- token_user = user for writing output via API
	- if = input forms (comma-delimited) to summarize
	- df = destination field name for name of field to write output to (assumes classical project as this time)
	- xf = excluded fields (comma-delimited) to exclude from report

	example:  https://redcap.stanford.edu/plugins/summarize/?token=xxxxxx&if=form_a,form_b&df=form_a_b_summary&xf=record_id,salary

	
**/	

define('LOG_PREFIX', '/var/log/redcap/summarize');
define('LOG_DEBUG_ENABLED', TRUE);
define('LOG_DEBUG_SCREEN', FALSE);

// Obtain details from DET post
$project_id = voefr('project_id');
$record = voefr('record');
$instrument = voefr('instrument');
$redcap_event_name = voefr('redcap_event_name');
$redcap_data_access_group = voefr('redcap_data_access_group');
$instrument_complete = voefr($instrument . "_complete");
$redcap_url = voefr('redcap_url');
//logIt("POST:".print_r($_POST,true));

$api_url = $redcap_url . "api/";
// At stanford I have to strip off the https when calling the server from itself...
$api_url = str_replace("https://redcap.stanford.edu","http://redcap.stanford.edu",$api_url);
define('API_URL', $api_url);
//logIt("API URL: $api_url");

// Obtain parameters from DET query string
$token_user = voefr('token_user');
$input_forms = voefr('if');
$destination_field = voefr('df');
$excluded_fields = voefr('xf');

// Validate
if (!$record) {
	logIt('No record id parsed.');
	exit();
}

if (!$token_user || !$input_forms || !$destination_field) {
	logIt ("Invalid params: token_user=$token_user, if=$input_forms, df=$destination_field, xf=$excluded_fields");
	exit();
}

// Parse input forms and abort if there isn't a match
$arr_input_forms = explode(",", $input_forms);
if (!in_array($instrument, $arr_input_forms)) {
	logIt("Skipping - calling form $instrument is not in trigger list (if): ".json_encode($arr_input_forms), "DEBUG");
	exit();
}

// Link to REDCap as a Plugin!
define('NOAUTH',true);	// Turn off Authentication - running on server
$_GET['pid'] = $project_id; // Set the pid so context is Project rather than Global
require_once "../../redcap_connect.php";

// Lookup the API token for the supplied token_user
$sql = sprintf("SELECT api_token FROM redcap.redcap_user_rights where project_id = '%s' and username = '%s' AND api_token IS NOT NULL limit 1",
	db_real_escape_string(strip_tags($project_id)),
	db_real_escape_string(strip_tags($token_user))
);
$q = db_query($sql);
if (db_num_rows($q) < 1) {
	logIt('Unable to find a valid API token for $token_user.');
	exit();
}

$token = db_result($q, 0, 'api_token');
define('API_TOKEN',$token);

// Get all fields from the specified instruments
$all_fields = REDCap::getFieldNames($arr_input_forms);
//logIt("Fields are: " . json_encode($all_fields), "DEBUG");

// Get all excluded fields
$arr_excluded_fields = explode(",", $excluded_fields);

// Also exclude form_complete fields
foreach ($arr_input_forms as $v) $arr_excluded_fields[] = $v."_complete"; 
//logIt("Excluded fields are: " . json_encode($arr_excluded_fields), "DEBUG");

// Determine which fields to grab from project
$fields = array_diff($all_fields, $arr_excluded_fields);
//logIt("Summarize fields are: " . json_encode($fields), "DEBUG");

// Get Data
$data = REDCap::getData('array', $record, $fields, NULL, NULL, FALSE, FALSE, FALSE, NULL, TRUE);
$data = $data[$record];
$data = current($data);
//logIt("Data from fields are: " . print_r($data,true), "DEBUG");

// Get Metadata
$md = $Proj->metadata;

// Fix enumerated values
$summary = array();
foreach ($fields as $field_name) {
	$type = REDCap::getFieldType($field_name);
	//logIt("$field_name is $type");
	switch ($type) {
		case 'checkbox':
			$new = array();
			$enums = parseEnum($md[$field_name]['element_enum']);
			foreach ($data[$field_name] as $k => $v) {
				if ($v == 1) $new[$k] = $enums[$k];
			}
			//$data[$field_name] = implode(", ", $new);
			$text = implode("\n", $new);
			break;
		case 'radio':
		case 'dropdown':
		case 'yesno':
		case 'truefalse':
			$enums = parseEnum($md[$field_name]['element_enum']);
			//$data[$field_name] = $enums[$data[$field_name]];
			$text = $enums[$data[$field_name]];
			break;
		default:
			$text = $data[$field_name];
			break;
	}
	
	if (!empty($text)) {
		$summary[$field_name] = array(
			'label' => $md[$field_name]['element_label'],
			'text' => $text
		);
	}
}
//logIt("Fixed data are: " . print_r($data,true), "DEBUG");
//logIt("Summary data are: " . print_r($summary,true), "DEBUG");
//logIt("Metadata: " . print_r($Proj->metadata,true),"DEBUG");

$html = "<div style='background-color: #fefefe; padding:5px;'><table style='border: 1px solid #fefefe; border-spacing: 0px;width:100%; ' >";
$odd = false;
foreach ($summary as $field_name => $v) {
	$text = $v['text'];
	$len = strlen($text);
	$text = str_replace("\n","<br/>",$text);
	$color = ($odd ? '#fefefe' : '#fafafa');
	if ($len < 80) {
		$html .= "<tr style='background: $color;'><td style='padding: 5px;' valign='top'>{$v['label']}</td><td style='font-weight:normal;'>{$v['text']}</td></tr>";
	} else {
		$html .= "<tr style='background: $color;'><td style='padding: 5px;' colspan=2>{$v['label']}<div style='font-weight:normal;padding:5px 20px;'>{$v['text']}</div></td></tr>";
	}
	$odd = !$odd;
}
$html .= "</table></div>";

// Write back to project
$data = array(
	REDCap::getRecordIdField() => $record,
	$destination_field => $html
);

$result = writeToApi($data);
//logIt("WriteToApi Result: " . json_encode($result),"DEBUG");

logIt("Summarized $destination_field for record $record","INFO");

//-----------------------------------------------------------------

function logIt($msg, $level = "INFO") {
	// Skip if debug
	if ($level == "DEBUG" && LOG_DEBUG_ENABLED === FALSE) {
		//do nothing
	} else {
		file_put_contents( LOG_PREFIX . ".log",	date( 'Y-m-d H:i:s' ) . "\t" . $level . "\t" . $msg . "\n", FILE_APPEND );
		if (LOG_DEBUG_SCREEN === TRUE) echo "<pre>".$msg."\n</pre>";
	}
}

// Get Variable Or Empty string Fom _REQUEST
function voefr($var) {
	$result = isset($_REQUEST[$var]) ? $_REQUEST[$var] : "";
	return $result;
}

// Submit data to the REDCap API (used for updating values)
function writeToApi($data) {
	$params = array(
		'token' => API_TOKEN,
		'content' => 'record',
		'format' => 'json',
		'type' => 'flat',
		'data' => json_encode(array($data))
	);
	$result = http_post(API_URL, $params);
	//logIt("writeToApi URL: " . API_URL, "DEBUG");
	//logIt("writeToApi params: " . print_r($params,true), "DEBUG");
	//logIt("writeToApi result: " . $result, "DEBUG");

	$j = json_decode($result);
	
	if ($j->{'error'}) {
		logIt('Error writing to API: '.$j->{'error'});
		REDCap::logEvent("Error writing to API: " .$j->{'error'});
	}

	return $result;
}



?>
