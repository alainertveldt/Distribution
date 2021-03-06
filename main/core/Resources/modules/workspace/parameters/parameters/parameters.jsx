import React from 'react'
import {PropTypes as T} from 'prop-types'
import {connect} from 'react-redux'

import {trans} from '#/main/core/translation'

import {FormContainer} from '#/main/core/data/form/containers/form.jsx'
import {select as formSelect} from '#/main/core/data/form/selectors'
import {actions as formActions} from '#/main/core/data/form/actions'

import {PageActions} from '#/main/core/layout/page/components/page-actions.jsx'
import {FormPageActionsContainer} from '#/main/core/data/form/containers/page-actions.jsx'


const Actions = () =>
  <PageActions>
    <FormPageActionsContainer
      formName="parameters"
      target={(workspace) => ['apiv2_workspace_update', {id: workspace.id}]}
      opened={true}
    />
  </PageActions>

const Parameters = () => {
  return (
    <div>
      <FormContainer
        level={3}
        name="parameters"
        sections={[
          {
            id: 'information',
            icon: 'fa fa-question',
            title: trans('information'),
            defaultOpened: false,
            primary: false,
            fields: [
              {
                name: 'meta.created',
                type: 'date',
                label: trans('creation_date'),
                required: true,
                readOnly: true
              },
              {
                name: 'meta.creator.username',
                type: 'string',
                label: trans('creator'),
                required: true,
                readOnly: true
              },
              {
                name: 'meta.usedStorage',
                type: 'string',
                label: trans('storage_used'),
                readOnly: true
              },
              {
                name: 'meta.totalUsers',
                type: 'number',
                readOnly: true,
                label: trans('registered_user_amount')
              },
              {
                name: 'meta.totalResources',
                type: 'number',
                readOnly: true,
                label: trans('count_resources')
              }
            ]
          },
          {
            id: 'properties',
            title: trans('properties'),
            primary: false,
            defaultOpened: true,
            fields: [
              {
                name: 'name',
                type: 'string',
                label: trans('name'),
                required: true
              },
              {
                name: 'code',
                type: 'string',
                label: trans('code'),
                required: true
              },
              {
                name: 'meta.description',
                type: 'html',
                label: trans('description'),
                required: false
              },
              {
                name: 'thumbnail',
                type: 'image',
                label: trans('thumbnail')
              },
              {
                name: 'notifications',
                type: 'boolean',
                label: trans('notifications')
              }
            ]
          },
          {
            id: 'restrictions',
            title: trans('access_restrictions'),
            fields: [
              {
                name: 'restrictions.accessibleFrom',
                type: 'date',
                label: trans('opening_date')
              },
              {
                name: 'restrictions.accessibleUntil',
                type: 'date',
                label: trans('expiration_date')
              },
              {
                name: 'restrictions.maxStorage',
                type: 'string',
                label: trans('max_storage_size')
              },
              {
                name: 'restrictions.maxResources',
                type: 'integer',
                label: trans('max_amount_resources')
              },
              {
                name: 'restrictions.hidden',
                type: 'boolean',
                label: trans('hide')
              }
            ]
          }
        ]}
      />
    </div>
  )
}

Parameters.propTypes = {
  workspace: T.shape({
    roles: T.arrayOf(T.shape({
      id: T.string.isRequired,
      translationKey: T.string.isRequired
    }))
  }).isRequired,
  updateProp: T.func.isRequired
}

const ConnectedParameters = connect(
  state => ({
    workspace: formSelect.data(formSelect.form(state, 'parameters'))
  }),
  dispatch => ({
    updateProp(propName, propValue) {
      dispatch(formActions.updateProp('parameters', propName, propValue))
    }
  })
)(Parameters)

export {
  ConnectedParameters as ParametersTab,
  Actions as ParametersActions
}
