const sbText = {
    SB_INTRO: 'Create reusable content here. Insert a link or an independent copy in a page; creating a block does not place it on your site.',
    SB_DRAFT_HELP: 'Save keeps your draft private. Publish updates every linked use of this block.',
    SB_NAME_HELP: 'Internal name, not a visible heading.',
    SB_PREVIEW_HELP: 'The standalone preview shows this draft. A page preview replaces existing links to this block only for you.',
    SB_FOOTER_HELP: 'Choose published content for the additional theme footer. Apply changes the placement immediately; it does not publish drafts.',
    SB_COPY_HELP: 'Linked content follows future publications. A copy becomes ordinary content in this page draft.',
    SB_LINKED_HELP: 'This content is managed centrally. Edit its source to update all linked uses, or detach a copy for this page.',
    SB_HISTORY_HELP: 'Restore creates a draft; publish it to make the restored content live. Page revisions restore references, not historical source content.',
    SB_EXPORT_HELP: 'Export includes this project’s blocks, history and footer assignment. Local media is included in the site export under Recycle Bin. Import restores IDs and refuses existing blocks.',
    SB_NOT_FOUND: 'Block not found.', SB_NOT_AVAILABLE: 'Choose a published, unarchived block.',
    SB_CONFLICT: 'This content changed elsewhere. Reload before saving.', SB_FORBIDDEN: 'You do not have permission for this action.',
    SB_NAME_REQUIRED: 'Enter a name of up to 160 characters.', SB_TOO_LARGE: 'Content exceeds 200 KB.',
    SB_NO_NESTING: 'Library blocks cannot contain links to other library blocks. Insert a local copy instead.',
    SB_CANNOT_PUBLISH: 'Add content and unarchive this block before publishing.', SB_IN_USE: 'This block is still used. Remove its page and footer links first.',
    SB_BAD_SCOPE: 'Choose a valid page in this project.', SB_BAD_ACTION: 'Unknown action.', SB_BAD_INPUT: 'Invalid request fields.',
    SB_STORAGE_ERROR: 'Content could not be stored. Please try again.', SB_BAD_IMPORT: 'Invalid export or mismatched project.',
    SB_IMPORT_EXISTS: 'Import would overwrite existing blocks or a footer assignment.',
    SB_CONNECTION: 'Could not connect. Please try again.', SB_LEAVE: 'Discard unsaved changes?',
    SB_DELETE: 'Delete this unused block permanently?', SB_MISSING_REF: 'This linked block is unavailable in this project. Choose another published block.',
};
function sbT(value) {
    const translated = translatefilter.translate(value || '');
    return translated === value && Object.hasOwn(sbText, value) ? sbText[value] : translated;
}
