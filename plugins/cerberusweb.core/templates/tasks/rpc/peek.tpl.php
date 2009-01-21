<table cellpadding="0" cellspacing="0" border="0" width="98%">
	<tr>
		<td align="left" width="0%" nowrap="nowrap" style="padding-right:5px;"><img src="{devblocks_url}c=resource&p=cerberusweb.core&f=images/gear.gif{/devblocks_url}" align="absmiddle"></td>
		<td align="left" width="100%" nowrap="nowrap"><h1>Tasks</h1></td>
	</tr>
</table>

<form action="{devblocks_url}{/devblocks_url}" method="POST" id="formTaskPeek" name="formTaskPeek" onsubmit="return false;">
<input type="hidden" name="c" value="tasks">
<input type="hidden" name="a" value="saveTaskPeek">
<input type="hidden" name="id" value="{$task->id}">
<input type="hidden" name="view_id" value="{$view_id}">
<input type="hidden" name="do_delete" value="0">

<div style="height:350px;overflow:auto;margin:2px;padding:3px;">

<table cellpadding="0" cellspacing="2" border="0" width="98%">
	{if !empty($link_namespace) && !empty($link_object_id)}
	<tr>
		<td width="0%" nowrap="nowrap" align="right" valign="top">Link: </td>
		<td width="100%">
			<input type="hidden" name="link_namespace" value="{$link_namespace}">
			<input type="hidden" name="link_object_id" value="{$link_object_id}">
			{$link_namespace}={$link_object_id}
		</td>
	</tr>
	{/if}
	{if !empty($source_info)}
	<tr>
		<td width="0%" nowrap="nowrap" align="right" valign="top">Source: </td>
		<td width="100%">
			<a href="{$source_info.url}" title="{$source_info.name|escape}">{$source_info.name|truncate:75:'...':true}</a>
		</td>
	</tr>
	{/if}
	<tr>
		<td width="0%" nowrap="nowrap" align="right">Title: </td>
		<td width="100%">
			<input type="text" name="title" style="width:98%;" value="{$task->title|escape}">
		</td>
	</tr>
	<tr>
		<td width="0%" nowrap="nowrap" align="right" valign="top">Due: </td>
		<td width="100%">
			<input type="text" name="due_date" size="45" value="{if !empty($task->due_date)}{$task->due_date|devblocks_date}{/if}"><button type="button" onclick="ajax.getDateChooser('dateTaskDue',this.form.due_date);">&nbsp;<img src="{devblocks_url}c=resource&p=cerberusweb.core&f=images/calendar.gif{/devblocks_url}" align="top">&nbsp;</button>
			<div id="dateTaskDue" style="display:none;position:absolute;z-index:1;"></div>
		</td>
	</tr>
	<tr>
		<td width="0%" nowrap="nowrap" align="right">Priority: </td>
		<td width="100%">
			<label><input type="radio" name="priority" value="4" {if empty($task->priority) || 4==$task->priority}checked{/if}> {$translate->_('priority.none')|capitalize}</label>
			<label><input type="radio" name="priority" value="3" {if 3==$task->priority}checked{/if}> {$translate->_('priority.low')|capitalize}</label>
			<label><input type="radio" name="priority" value="2" {if 2==$task->priority}checked{/if}> {$translate->_('priority.normal')|capitalize}</label>
			<label><input type="radio" name="priority" value="1" {if 1==$task->priority}checked{/if}> {$translate->_('priority.high')|capitalize}</label>
		</td>
	</tr>
	<tr>
		<td width="0%" nowrap="nowrap" align="right">Worker: </td>
		<td width="100%">
			<select name="worker_id">
				<option value="0">&nbsp;</option>
				{foreach from=$workers item=worker key=worker_id name=workers}
				{if $worker_id==$active_worker->id}{assign var=active_worker_sel_id value=$smarty.foreach.workers.iteration}{/if}
				<option value="{$worker_id}" {if $worker_id==$task->worker_id}selected{/if}>{$worker->getName()}</option>
				{/foreach}
			</select>{if !empty($active_worker_sel_id)}<button type="button" onclick="this.form.worker_id.selectedIndex = {$active_worker_sel_id};">me</button>{/if}
		</td>
	</tr>
	<tr>
		<td width="0%" nowrap="nowrap" align="right" valign="top">Description: </td>
		<td width="100%">
			<textarea name="content" style="width:98%;height:100px;">{$task->content|escape}</textarea>
		</td>
	</tr>
	<tr>
		<td width="0%" nowrap="nowrap" align="right" valign="top"><label for="checkTaskCompleted">Completed:</label> </td>
		<td width="100%">
			<input id="checkTaskCompleted" type="checkbox" name="completed" value="1" {if $task->is_completed}checked{/if}>
		</td>
	</tr>
</table>

<table cellpadding="0" cellspacing="2" border="0" width="98%">
	<!-- Custom Fields -->
	<tr>
		<td colspan="2" align="center">&nbsp;</td>
	</tr>
	{foreach from=$task_fields item=f key=f_id}
		<tr>
			<td valign="top" width="25%" align="right">
				<input type="hidden" name="field_ids[]" value="{$f_id}">
				<span style="font-size:90%;">{$f->name}:</span>
			</td>
			<td valign="top" width="75%">
				{if $f->type=='S'}
					<input type="text" name="field_{$f_id}" size="45" maxlength="255" value="{$task_field_values.$f_id}"><br>
				{elseif $f->type=='T'}
					<textarea name="field_{$f_id}" rows="4" cols="50" style="width:98%;">{$task_field_values.$f_id}</textarea><br>
				{elseif $f->type=='C'}
					<input type="checkbox" name="field_{$f_id}" value="1" {if $task_field_values.$f_id}checked{/if}><br>
				{elseif $f->type=='D'}
					<select name="field_{$f_id}">{* [TODO] Fix selected *}
						<option value=""></option>
						{foreach from=$f->options item=opt}
						<option value="{$opt|escape}" {if $opt==$task_field_values.$f_id}selected{/if}>{$opt}</option>
						{/foreach}
					</select><br>
				{elseif $f->type=='E'}
					<input type="text" name="field_{$f_id}" size="30" maxlength="255" value="{if !empty($task_field_values.$f_id)}{$task_field_values.$f_id|devblocks_date}{/if}"><button type="button" onclick="ajax.getDateChooser('dateCustom{$f_id}',this.form.field_{$f_id});">&nbsp;<img src="{devblocks_url}c=resource&p=cerberusweb.core&f=images/calendar.gif{/devblocks_url}" align="top">&nbsp;</button>
					<div id="dateCustom{$f_id}" style="display:none;position:absolute;z-index:1;"></div>
				{/if}	
			</td>
		</tr>
	{/foreach}
</table>

</div>

<button type="button" onclick="genericPanel.hide();genericAjaxPost('formTaskPeek', 'view{$view_id}')"><img src="{devblocks_url}c=resource&p=cerberusweb.core&f=images/check.gif{/devblocks_url}" align="top"> {$translate->_('common.save_changes')}</button>
{if !empty($task) && ($active_worker->is_superuser || $active_worker->id == $task->worker_id)}
	<button type="button" onclick="if(confirm('Are you sure you want to permanently delete this task?')){literal}{{/literal}this.form.do_delete.value='1';genericPanel.hide();genericAjaxPost('formTaskPeek', 'view{$view_id}');{literal}}{/literal}"><img src="{devblocks_url}c=resource&p=cerberusweb.core&f=images/delete2.gif{/devblocks_url}" align="top"> {$translate->_('common.delete')|capitalize}</button>
{/if}
<button type="button" onclick="genericPanel.hide();"><img src="{devblocks_url}c=resource&p=cerberusweb.core&f=images/delete.gif{/devblocks_url}" align="top"> {$translate->_('common.cancel')|capitalize}</button>
<br>
</form>
