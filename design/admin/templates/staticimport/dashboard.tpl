{* staticimport/dashboard: the import types, their state and runs (no inline CSS: admin4 components only) *}
<div class="a4-page">
<div class="box-header"><h1 class="context-title">{'Static site import'|i18n( 'extension/exp_static_html_imports' )}</h1></div>
<p>{'Imports a static site into the content tree by content classes, and keeps it in step with its source. A run started here works in the background.'|i18n( 'extension/exp_static_html_imports' )}</p>
{foreach $types as $type}
<div class="a4-card">
    <h2>--{$type.label|wash} <span class="a4-tag">{$type.class|wash}</span></h2>
    <p>{'Source'|i18n( 'extension/exp_static_html_imports' )}: {if $type.source}{$type.source|wash}{else}<em>{'none set'|i18n( 'extension/exp_static_html_imports' )}</em>{/if}</p>
    <p>{'%pages pages, %media media'|i18n( 'extension/exp_static_html_imports', , hash( '%pages', $type.pages, '%media', $type.assets ) )}
    {foreach $type.by_status as $status => $count} <span class="a4-badge">{$status|wash} {$count}</span>{/foreach}</p>
    {if $type.run_in_progress}
    <p><strong>{'A run is in progress'|i18n( 'extension/exp_static_html_imports' )}</strong>: {$type.run_in_progress.id|wash}, {$type.run_in_progress.phase|wash} {$type.run_in_progress.position}</p>
    {/if}
    {if $type.runs|count}
    <table class="a4-table list" cellspacing="0">
    <tr><th>{'Run'|i18n( 'extension/exp_static_html_imports' )}</th><th>{'Finished'|i18n( 'extension/exp_static_html_imports' )}</th><th>{'Created'|i18n( 'extension/exp_static_html_imports' )}</th><th>{'Updated'|i18n( 'extension/exp_static_html_imports' )}</th><th>{'Flagged'|i18n( 'extension/exp_static_html_imports' )}</th><th>{'Failed'|i18n( 'extension/exp_static_html_imports' )}</th></tr>
    {foreach $type.runs|extract_left( 10 ) as $run}
    <tr><td><a href={concat( 'staticimport/report/', $type.label, '/', $run.id )|ezurl}>{$run.id|wash}</a></td><td>{$run.finished|l10n( 'shortdatetime' )}</td><td>{$run.counts.created}</td><td>{$run.counts.updated}</td><td>{$run.counts.flagged}</td><td>{$run.counts.failed}</td></tr>
    {/foreach}
    </table>
    {/if}
    {if $can_run}
    <form method="post" action={'staticimport/run'|ezurl}>
        <input type="hidden" name="Type" value="{$type.label|wash}" />
        <label><input type="checkbox" name="DryRun" value="1" /> {'Dry run (write nothing)'|i18n( 'extension/exp_static_html_imports' )}</label>
        <label><input type="checkbox" name="ApplyRemovals" value="1" /> {'Move pages that left the source to the trash'|i18n( 'extension/exp_static_html_imports' )}</label>
        <input class="a4-btn button" type="submit" name="StartButton" value="{'Start a run'|i18n( 'extension/exp_static_html_imports' )}" />
    </form>
    {/if}
</div>
{/foreach}
</div>
