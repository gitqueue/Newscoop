<?php
camp_load_translation_strings("plugin_debate");

if (!SecurityToken::isValid()) {
    camp_html_display_error(getGS('Invalid security token!'));
    exit;
}

// Check permissions
if (!$g_user->hasPermission('plugin_debate_admin')) {
    camp_html_display_error(getGS('You do not have the right to manage debates.'));
    exit;
}

$f_debate_nr = Input::Get('f_debate_nr', 'int');
$f_fk_language_id = Input::Get('f_fk_language_id', 'int');

$f_title = Input::Get('f_title', 'string');
$f_question = Input::Get('f_question', 'string');
$f_date_begin = Input::Get('f_date_begin', 'string');
$f_date_end = Input::Get('f_date_end', 'string');
$f_votes_per_user = Input::Get('f_votes_per_user', 'int');
$f_is_extended = Input::Get('f_is_extended', 'boolean');
$f_nr_of_answers = Input::Get('f_nr_of_answers', 'int');
$f_allow_not_logged_in =  Input::Get('f_allow_not_logged_in', 'int');

$f_answers = Input::Get('f_answer', 'array');
$f_onhitlist = Input::Get('f_onhitlist', 'array');

$debate = new Debate($f_fk_language_id, $f_debate_nr);

if ($debate->exists()) {
    // update existing debate
    $debate = new Debate($f_fk_language_id, $f_debate_nr);
    $debate->setProperty('title', $f_title);
    $debate->setProperty('question', $f_question);
    $debate->setProperty('date_begin', $f_date_begin);
    $debate->setProperty('date_end', $f_date_end);
    $debate->setProperty('votes_per_user', $f_votes_per_user);
    $debate->setProperty('nr_of_answers', $f_nr_of_answers);
    $debate->setProperty('is_extended', $f_is_extended);
    $debate->setProperty('allow_not_logged_in', $f_allow_not_logged_in);

    foreach ($f_answers as $nr_answer => $text) {
        if (trim($text) != '') {
            $answer = new DebateAnswer($f_fk_language_id, $f_debate_nr, $nr_answer);
            if ($answer->exists()) {
                $answer->setProperty('answer', $text);
            } else {
                $answer->create($text);
            }
        }
    }

    DebateAnswer::SyncNrOfAnswers($f_fk_language_id, $f_debate_nr);

} else {
    // create new debate
    $debate = new Debate($f_fk_language_id);
    $success = $debate->create($f_title, $f_question, $f_date_begin, $f_date_end, $f_nr_of_answers, $f_votes_per_user);

    if ($success) {
        $debate->setProperty('is_extended', $f_is_extended);

        foreach ($f_answers as $nr_answer => $text) {
            if (trim($text) != '') {
                $answer = new DebateAnswer($f_fk_language_id, $debate->getNumber(), $nr_answer);
                $success = $answer->create($text);
            }
        }
    }
}
$f_from = Input::Get('f_from', 'string', 'index.php');
header('Location: '.$f_from);
exit;
