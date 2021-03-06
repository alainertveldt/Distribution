import React from 'react'
import {shallow} from 'enzyme'
import {spyConsole, renew, ensure, mockGlobals} from '#/main/core/scaffolding/tests'
import {ValidationStatus} from './validation-status.jsx'

describe('<ValidationStatus/>', () => {
  beforeEach(() => {
    spyConsole.watch()
    renew(ValidationStatus, 'ValidationStatus')
  })
  afterEach(spyConsole.restore)

  it('renders an error status icon', () => {
    const block = shallow(
      React.createElement(ValidationStatus, {
        id: 'ID',
        validating: true
      })
    )

    ensure.propTypesOk()
    ensure.equal(block.find('.fa-warning').length, 1)
  })
})
