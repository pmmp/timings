/*
 * Aikar's Minecraft Timings Parser
 *
 * Written by Aikar <aikar@aikar.co>
 * http://aikar.co
 * http://starlis.com
 *
 * @license MIT
 */

function showMyChildren() {
    showChildren($(this));
}
function showChildrenStep(element, depth) {
    $(element).show()

    if ($(element).hasClass('hidden-children')) {
        $(element).off("click").click(hideMyChildren).addClass('visible-children').removeClass('hidden-children');
        var last;
        var children = $(element).data('children');
        var done = 1;
        $(element).nextUntil(
            function() {
                return $(this).data('depth') == depth || done >= 1000;
            }
        ).each(function() {
            last = $(this).data('depth');
            if ($(this).data('depth') == depth + 1){
                if (children == 1) { //this element is an only child, expand its children too
                    children = $(this).data('children');
                    depth++;
                    if ($(this).hasClass('hidden-children')) {
                        $(this).off("click").click(hideMyChildren).addClass('visible-children').removeClass('hidden-children');
                        done++;
                    }
                }
                $(this).show();
            }
        })
        if ($(last).data('depth') != depth) {
            setTimeout(showChildrenStep, 0, last, depth)
        }
    }
}

function showChildren(element) {
    var stopDepth = $(element).data('depth');
    setTimeout(showChildrenStep, 0, element, stopDepth);
}

function hideMyChildren() {
    hideChildren($(this));
}

function hideChildrenStep(element, depth) {
    if ($(element).hasClass('visible-children')) {
        var last;
        var done = 1;
        $(element).off("click").click(showMyChildren).addClass('hidden-children').removeClass('visible-children');
        $(element).nextUntil(function() {
            return $(this).data('depth') <= depth || done >= 1000;
        }).each(function() {
            last = $(this).data('depth');
            $(this).hide();
            if ($(this).hasClass('visible-children')) {
                $(this).off("click").click(showMyChildren).addClass('hidden-children').removeClass('visible-children');
                done++;
            }
        })
        if ($(last).data('depth') != depth){
            setTimeout(showChildrenStep, 0, last, depth)
        }
    }
}

function hideChildren(element){
    var stopDepth = $(element).data('depth');
    setTimeout(hideChildrenStep, 0, element, stopDepth);
}

function hideAll() {
    $(this).hide();
    hideChildren($(this));
}

function learnMore(ev) {
    ev.stopPropagation();
    $("#info-" + $(this).data('info')).dialog({width: "80%", modal: true});
}

$(document).ready(function() {
    $('#paste_toggle').click(function() {
        $('#paste').toggle();
    });
    $('.show_rest').click(function() {
        var border = $(this).closest('.timings-table-border');
        var rows = border.data('hidden-rows');

        if(border.data('expanded')) {
            border.data('expanded', false);
            border.find('.children-hidden-by-default').each(hideMyChildren);
            border.find('.hidden').each(hideAll);
            border.find('.show-rest-text').text('Show ' + rows + ' more rows');
            border.find('.expand-all-text').text('Expand all');
        }else{
            border.data('expanded', true);
            border.find('.event').each(showMyChildren);
            border.find('.show-rest-text').text('Hide ' + rows + ' rows');
            border.find('.expand-all-text').text('Collapse all');
        }
    })
    $('.show-hot-path').click(function() {
        var depth = 0;
        var first = $(this).closest('.timings-table-border').find('.event').first();
        showChildren(first);
        first.nextUntil(function() {
            var currentDepth = $(this).data('depth');
            if (currentDepth > depth) {
                depth = currentDepth;
                showChildren($(this));
                return false;
            } else {
                return true;
            }
        })
    })
    $('.event.hidden-children').off("click").click(showMyChildren);
    $('.event.visible-children').off("click").click(hideMyChildren);
    $('.event.hidden-children, .event.visible-children').mouseover(function() {
        var depth = $(this).data('depth');
        $(this).nextUntil(function() {
            return $(this).data('depth') <= depth;
        }).each(function() {
            if ($(this).data('depth') == depth + 1) {
                $(this).addClass('parent-hovered');
            } else {
                $(this).addClass('grandparent-hovered');
            }
        })
    })
    $('.event').mouseout(function() {
        var depth = $(this).data('depth');
        $(this).nextUntil(function() {
            return $(this).data('depth') <= depth;
        }).each(function() {
            $(this).removeClass('parent-hovered');
            $(this).removeClass('grandparent-hovered');
        })
    })
    $('.learnmore').click(learnMore);
});
