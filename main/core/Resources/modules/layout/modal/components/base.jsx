import React from 'react'
import {PropTypes as T} from 'prop-types'

import classes from 'classnames'
import Modal from 'react-bootstrap/lib/Modal'

const BaseModal = props =>
  <Modal
    bsSize={props.bsSize}
    show={props.show}
    onHide={props.fadeModal}
    onExited={props.hideModal}
    dialogClassName={props.className}
  >
    {props.title &&
      <Modal.Header closeButton>
        <Modal.Title>
          {props.icon &&
            <span className={classes('modal-icon', props.icon)} />
          }

          {props.title}

          {props.subtitle &&
            <small className={classes({
              'with-icon': !!props.icon
            })}>{props.subtitle}</small>
          }
        </Modal.Title>
      </Modal.Header>
    }

    {props.children}
  </Modal>

BaseModal.propTypes = {
  bsSize: T.string,
  fadeModal: T.func.isRequired,
  hideModal: T.func.isRequired,
  show: T.bool.isRequired,
  icon: T.string,
  title: T.string,
  subtitle: T.string,
  className: T.string,
  children: T.node.isRequired
}

// required when testing proptypes on code instrumented by istanbul
// @see https://github.com/facebook/jest/issues/1824#issuecomment-250478026
BaseModal.displayName = 'BaseModal'

export {BaseModal}
