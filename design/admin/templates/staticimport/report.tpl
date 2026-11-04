{* staticimport/report: the Markdown report of one run *}
<div class="a4-page">
<div class="box-header"><h1 class="context-title">{'Report of the run %run of --%type'|i18n( 'extension/exp_static_html_imports', , hash( '%run', $run, '%type', $type ) )|wash}</h1></div>
<div class="a4-card"><pre>{$report|wash}</pre></div>
<p><a href={'staticimport/dashboard'|ezurl}>{'Back to the static site import'|i18n( 'extension/exp_static_html_imports' )}</a></p>
</div>
