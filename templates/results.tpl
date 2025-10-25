{**
 * templates/results.tpl
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Template for displaying file integrity scan results.
 *}
<div id="scanResults">

    {if $error}
        <div class="pkp_notification pkp_notification_error">{$error|escape}</div>
    {elseif $scanRan}
        <h2>{translate key="plugins.generic.fileIntegrity.results.title"}</h2>

        {if !$modifiedFiles && !$deletedFiles && !$addedFiles}
            <div class="pkp_notification pkp_notification_success">
                {translate key="plugins.generic.fileIntegrity.results.noIssues"}</div>
        {else}
            <p>{translate key="plugins.generic.fileIntegrity.results.introduction"}</p>

            {if $modifiedFiles}
                <h3>{translate key="plugins.generic.fileIntegrity.results.modified"}</h3>
                <table class="pkp_table">
                    <thead>
                        <tr>
                            <th>{translate key="plugins.generic.fileIntegrity.results.filePath"}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$modifiedFiles item=hash key=file}
                            <tr>
                                <td>{$file|escape}</td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            {/if}

            {if $deletedFiles}
                <h3>{translate key="plugins.generic.fileIntegrity.results.deleted"}</h3>
                <table class="pkp_table">
                    <thead>
                        <tr>
                            <th>{translate key="plugins.generic.fileIntegrity.results.filePath"}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$deletedFiles item=file}
                            <tr>
                                <td>{$file|escape}</td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            {/if}

            {if $addedFiles}
                <h3>{translate key="plugins.generic.fileIntegrity.results.added"}</h3>
                <table class="pkp_table">
                    <thead>
                        <tr>
                            <th>{translate key="plugins.generic.fileIntegrity.results.filePath"}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$addedFiles item=file}
                            <tr>
                                <td>{$file|escape}</td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            {/if}
        {/if}
    {/if}

</div>