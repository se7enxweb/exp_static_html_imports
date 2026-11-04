{* The admin dashboard block of the static site import (dashboard.ini): the last run and the counts of each type that
   has run, and a run in progress; for users with staticimport/read *}
{if fetch( 'user', 'has_access_to', hash( 'module', 'staticimport', 'function', 'read' ) )}
<div class="a4-card">
<h2><a href={'staticimport/dashboard'|ezurl}>{'Static site import'|i18n( 'extension/exp_static_html_imports' )}</a></h2>
{def $types = fetch( 'staticimport', 'status', hash() )}
{if $types|count|eq( 0 )}<p>{'No import has run yet.'|i18n( 'extension/exp_static_html_imports' )}</p>{/if}
{foreach $types as $label => $type}
<p><strong>--{$label|wash}</strong>: {'%pages pages'|i18n( 'extension/exp_static_html_imports', , hash( '%pages', $type.pages ) )}
{foreach $type.by_status as $status => $count} <span class="a4-badge">{$status|wash} {$count}</span>{/foreach}
{if $type.run_in_progress}<br />{'In progress: %phase %position'|i18n( 'extension/exp_static_html_imports', , hash( '%phase', $type.run_in_progress.phase, '%position', $type.run_in_progress.position ) )}{/if}</p>
{/foreach}
{undef $types}
</div>
{/if}
