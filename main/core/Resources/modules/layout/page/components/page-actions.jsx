import React from 'react'
import classes from 'classnames'

import {trans} from '#/main/core/translation'

import {PropTypes as T, implementPropTypes} from '#/main/core/scaffolding/prop-types'
import {Button} from '#/main/app/action/components/button'
import {DropdownButton} from '#/main/app/action/components/dropdown-button'
import {Action as ActionTypes} from '#/main/app/action/prop-types'

/**
 * Base component for each page actions.
 *
 * @param props
 * @constructor
 */
const PageAction = props =>
  <Button
    {...props}
    tooltip="bottom"
    className={classes('page-action-btn', props.className)}
  >
    {props.children}
  </Button>

implementPropTypes(PageAction, ActionTypes, {
  className: T.string,
  children: T.any
})

/**
 * Toggles fullscreen mode.
 *
 * @param props
 * @constructor
 */
const FullScreenAction = props =>
  <PageAction
    type="callback"
    label={trans(props.fullscreen ? 'fullscreen_off' : 'fullscreen_on')}
    icon={classes('fa', {
      'fa-expand': !props.fullscreen,
      'fa-compress': props.fullscreen
    })}
    callback={props.toggleFullscreen}
  />

FullScreenAction.propTypes = {
  fullscreen: T.bool.isRequired,
  toggleFullscreen: T.func.isRequired
}

const MoreAction = props =>
  <DropdownButton
    {...props}
    tooltip="bottom"
    className={classes('page-action-btn', props.className)}
    icon="fa fa-ellipsis-v"
    label={trans('show_more_actions')}
    pullRight={true}
  />

implementPropTypes(MoreAction, DropdownButton, {}, {
  menuLabel: trans('more_actions')
})

/**
 * Groups some actions together.
 *
 * @todo groups should be named
 *
 * @param props
 * @constructor
 */
const PageGroupActions = props =>
  <div role="toolbar" className={classes('page-actions-group', props.className)}>
    {props.children}
  </div>

PageGroupActions.propTypes = {
  className: T.string,
  children: T.node.isRequired
}

/**
 * Creates actions bar for a page.
 *
 * @param props
 * @constructor
 */
const PageActions = props =>
  <nav role="menubar" className={classes('page-actions', props.className)}>
    {props.children}
  </nav>

PageActions.propTypes = {
  className: T.string,
  children: T.node.isRequired
}

export {
  PageAction,
  FullScreenAction,
  MoreAction,
  PageGroupActions,
  PageActions
}
