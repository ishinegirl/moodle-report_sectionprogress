<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Section progress reports
 *
 * @package    report
 * @subpackage sectionprogress
 * @copyright  2017 Justin Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/completionlib.php');

define('COMPLETION_REPORT_PAGE', 25);

// Get course
$id = required_param('course',PARAM_INT);
$course = $DB->get_record('course',array('id'=>$id));
if (!$course) {
    print_error('invalidcourseid');
}
$context = context_course::instance($course->id);

//Get section completion info for bulding top bar
$modinfo = get_fast_modinfo($course);
$sect_compl_info = \availability_sectioncompleted\condition::get_section_completion_info(
    \availability_sectioncompleted\condition::ALL_SECTIONS,
    $course,
    $modinfo);

// Sort (default lastname, optionally firstname)
$sort = optional_param('sort','',PARAM_ALPHA);
$firstnamesort = $sort == 'firstname';

// CSV format
$format = optional_param('format','',PARAM_ALPHA);
$excel = $format == 'excelcsv';
$csv = $format == 'csv' || $excel;

// Paging
$start   = optional_param('start', 0, PARAM_INT);
$sifirst = optional_param('sifirst', 'all', PARAM_NOTAGS);
$silast  = optional_param('silast', 'all', PARAM_NOTAGS);
$start   = optional_param('start', 0, PARAM_INT);

// Whether to show extra user identity information
$extrafields = get_extra_user_fields($context);
$leftcols = 1 + count($extrafields);

function csv_quote($value) {
    global $excel;
    if ($excel) {
        return core_text::convert('"'.str_replace('"',"'",$value).'"','UTF-8','UTF-16LE');
    } else {
        return '"'.str_replace('"',"'",$value).'"';
    }
}

$url = new moodle_url('/report/sectionprogress/index.php', array('course'=>$id));
if ($sort !== '') {
    $url->param('sort', $sort);
}
if ($format !== '') {
    $url->param('format', $format);
}
if ($start !== 0) {
    $url->param('start', $start);
}
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');

require_login($course);

// Check basic permission
require_capability('report/sectionprogress:view',$context);

// Get group mode
$group = groups_get_course_group($course,true); // Supposed to verify group
if ($group===0 && $course->groupmode==SEPARATEGROUPS) {
    require_capability('moodle/site:accessallgroups',$context);
}

// Get data on activities and sectionprogress of all users, and give error if we've
// nothing to display (no users or no activities)
$reportsurl = $CFG->wwwroot.'/course/report.php?id='.$course->id;
$sectionnames = $sect_compl_info->sectionnames;

// Generate where clause
$where = array();
$where_params = array();
/*
if ($sifirst !== 'all') {
    $where[] = $DB->sql_like('u.firstname', ':sifirst', false);
    $where_params['sifirst'] = $sifirst.'%';
}

if ($silast !== 'all') {
    $where[] = $DB->sql_like('u.lastname', ':silast', false);
    $where_params['silast'] = $silast.'%';
}
*/
$sifirst='all';
$silast='all';

// Get enrolled user count
$totalusers =0;
$users = get_enrolled_users($context);
if($users){
    $totalusers = count($users);
}


if ($csv && $totalusers && count($sectionnames)>0) { // Only show CSV if there are some users/actvs

    $shortname = format_string($course->shortname, true, array('context' => $context));
    header('Content-Disposition: attachment; filename=sectionprogress.'.
        preg_replace('/[^a-z0-9-]/','_',core_text::strtolower(strip_tags($shortname))).'.csv');
    // Unicode byte-order mark for Excel
    if ($excel) {
        header('Content-Type: text/csv; charset=UTF-16LE');
        print chr(0xFF).chr(0xFE);
        $sep="\t".chr(0);
        $line="\n".chr(0);
    } else {
        header('Content-Type: text/csv; charset=UTF-8');
        $sep=",";
        $line="\n";
    }
} else {

    // Navigation and header
    $strreports = get_string("reports");
    //TODO fix this JUSTIN 2017/07/20
    $strcompletion = get_string('activitycompletion', 'completion');

    $PAGE->set_title($strcompletion);
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();
    $PAGE->requires->js('/report/sectionprogress/textrotate.js');
    $PAGE->requires->js_function_call('textrotate_init', null, true);

    // Handle groups (if enabled)
    groups_print_course_menu($course,$CFG->wwwroot.'/report/sectionprogress/?course='.$course->id);
}

if (count($sectionnames)==0) {
    echo $OUTPUT->container(get_string('err_noactivities', 'completion'), 'errorbox errorboxcontent');
    echo $OUTPUT->footer();
    exit;
}

// If no users in this course what-so-ever
if (!$totalusers) {
    echo $OUTPUT->container(get_string('err_nousers', 'completion'), 'errorbox errorboxcontent');
    echo $OUTPUT->footer();
    exit;
}

// Build link for paging
$link = $CFG->wwwroot.'/report/sectionprogress/?course='.$course->id;
if (strlen($sort)) {
    $link .= '&amp;sort='.$sort;
}
$link .= '&amp;start=';

// Build the the page by Initial bar
$initials = array('first', 'last');
$alphabet = explode(',', get_string('alphabet', 'langconfig'));

$pagingbar = '';
foreach ($initials as $initial) {
    $var = 'si'.$initial;

    $othervar = $initial == 'first' ? 'silast' : 'sifirst';
    $othervar = $$othervar != 'all' ? "&amp;{$othervar}={$$othervar}" : '';

    $pagingbar .= ' <div class="initialbar '.$initial.'initial">';
    $pagingbar .= get_string($initial.'name').':&nbsp;';

    if ($$var == 'all') {
        $pagingbar .= '<strong>'.get_string('all').'</strong> ';
    }
    else {
        $pagingbar .= "<a href=\"{$link}{$othervar}\">".get_string('all').'</a> ';
    }

    foreach ($alphabet as $letter) {
        if ($$var === $letter) {
            $pagingbar .= '<strong>'.$letter.'</strong> ';
        }
        else {
            $pagingbar .= "<a href=\"$link&amp;$var={$letter}{$othervar}\">$letter</a> ";
        }
    }

    $pagingbar .= '</div>';
}

// Do we need a paging bar?
if ($totalusers > COMPLETION_REPORT_PAGE) {

    // Paging bar
    $pagingbar .= '<div class="paging">';
    $pagingbar .= get_string('page').': ';

    $sistrings = array();
    if ($sifirst != 'all') {
        $sistrings[] =  "sifirst={$sifirst}";
    }
    if ($silast != 'all') {
        $sistrings[] =  "silast={$silast}";
    }
    $sistring = !empty($sistrings) ? '&amp;'.implode('&amp;', $sistrings) : '';

    // Display previous link
    if ($start > 0) {
        $pstart = max($start - COMPLETION_REPORT_PAGE, 0);
        $pagingbar .= "(<a class=\"previous\" href=\"{$link}{$pstart}{$sistring}\">".get_string('previous').'</a>)&nbsp;';
    }

    // Create page links
    $curstart = 0;
    $curpage = 0;
    while ($curstart < $totalusers) {
        $curpage++;

        if ($curstart == $start) {
            $pagingbar .= '&nbsp;'.$curpage.'&nbsp;';
        } else {
            $pagingbar .= "&nbsp;<a href=\"{$link}{$curstart}{$sistring}\">$curpage</a>&nbsp;";
        }

        $curstart += COMPLETION_REPORT_PAGE;
    }

    // Display next link
    $nstart = $start + COMPLETION_REPORT_PAGE;
    if ($nstart < $totalusers) {
        $pagingbar .= "&nbsp;(<a class=\"next\" href=\"{$link}{$nstart}{$sistring}\">".get_string('next').'</a>)';
    }

    $pagingbar .= '</div>';
}

// Okay, let's draw the table of sectionprogress info,

// Start of table
if (!$csv) {
    print '<br class="clearer"/>'; // ugh

    print $pagingbar;

    if (!$totalusers) {
        echo $OUTPUT->heading(get_string('nothingtodisplay'));
        echo $OUTPUT->footer();
        exit;
    }

    print '<div id="completion-sectionprogress-wrapper" class="no-overflow">';
    print '<table id="completion-sectionprogress" class="generaltable flexible boxaligncenter" style="text-align:left"><thead><tr style="vertical-align:top">';

    // User heading / sort option
    print '<th scope="col" class="completion-sortchoice">';

    $sistring = "&amp;silast={$silast}&amp;sifirst={$sifirst}";

    if ($firstnamesort) {
        print
            get_string('firstname')." / <a href=\"./?course={$course->id}{$sistring}\">".
            get_string('lastname').'</a>';
    } else {
        print "<a href=\"./?course={$course->id}&amp;sort=firstname{$sistring}\">".
            get_string('firstname').'</a> / '.
            get_string('lastname');
    }
    print '</th>';

    // Print user identity columns
    foreach ($extrafields as $field) {
        echo '<th scope="col" class="completion-identifyfield">' .
                get_user_field_name($field) . '</th>';
    }
} else {
    foreach ($extrafields as $field) {
        echo $sep . csv_quote(get_user_field_name($field));
    }
}

// Activities
$formattedsections = array();
$sectionindex=0;
foreach($sectionnames as $section) {


    // Some names (labels) come URL-encoded and can be very long, so shorten them
    $displayname = $section;//format_string($section, true, array('context' => $context));

    if ($csv) {
        print $sep.csv_quote($displayname);
    } else {
        $shortenedname = shorten_text($displayname);
        print '<th scope="col" class="">'.
            '<a href="'.$CFG->wwwroot.'/course/view.php?id='.$course->id .'#section-' . $sectionindex . '"' .
            ' title="' . s($displayname) . '">'.
            '<span class="completion-activityname">'.
            $shortenedname.'</span></a>';
        print '</th>';
    }
    $formattedsections[$sectionindex] = (object)array(
        'displayname' => $displayname
    );
    $sectionindex++;
}

if ($csv) {
    print $line;
} else {
    print '</tr></thead><tbody>';
}

// Row for each user
foreach($users as $user) {

    $modinfo = get_fast_modinfo($course);
    $sect_compl_info = \availability_sectioncompleted\condition::get_section_completion_info(
        \availability_sectioncompleted\condition::ALL_SECTIONS,
        $course,
        $modinfo,
        false,
        $user->id);
    $sectionscompletions = $sect_compl_info->sectionscompletion;

    // User name
    if ($csv) {
        print csv_quote(fullname($user));
        foreach ($extrafields as $field) {
            echo $sep . csv_quote($user->{$field});
        }
    } else {
        print '<tr><th scope="row"><a href="'.$CFG->wwwroot.'/user/view.php?id='.
            $user->id.'&amp;course='.$course->id.'">'.fullname($user).'</a></th>';
        foreach ($extrafields as $field) {
            echo '<td>' . s($user->{$field}) . '</td>';
        }
    }

    // Progress for each activity
    $sectionindex=0;
    foreach($sectionscompletions as $completion) {


        // Work out how it corresponds to an icon
        switch($completion) {
            case false : $completiontype='n'; break;
            case true: $completiontype='y'; break;
        }

        $completionicon='completion-auto' .
            '-'.$completiontype;

        $describe = get_string('completion-' . $completiontype, 'completion');
        $a=new StdClass;
        $a->state=$describe;
        //$a->date=$date;
        $a->user=fullname($user);
        $a->activity = $sectionnames[$sectionindex];
        $fulldescribe=get_string('progress-title','completion',$a);

        if ($csv) {
            print $sep.csv_quote($describe).$sep.csv_quote($date);
        } else {
            print '<td class="completion-progresscell">'.
                '<img src="'.$OUTPUT->pix_url('i/'.$completionicon).
                '" alt="'.s($describe).'" title="'.s($fulldescribe).'" /></td>';
        }
        $sectionindex++;
    }

    if ($csv) {
        print $line;
    } else {
        print '</tr>';
    }
}

if ($csv) {
    exit;
}
print '</tbody></table>';
print '</div>';
print $pagingbar;

print '<ul class="sectionprogress-actions"><li><a href="index.php?course='.$course->id.
    '&amp;format=csv">'.get_string('csvdownload','completion').'</a></li>
    <li><a href="index.php?course='.$course->id.'&amp;format=excelcsv">'.
    get_string('excelcsvdownload','completion').'</a></li></ul>';

echo $OUTPUT->footer();

